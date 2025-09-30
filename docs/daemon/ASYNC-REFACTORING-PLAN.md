# Async Refactoring Plan: AsyncCloudClient

## Problem

`AsyncCloudClient::authenticate()` und `discoverDevices()` rufen **synchrone, blockierende** HTTP-Calls aus der `Connection`-Klasse auf (cURL). Dies blockiert den gesamten PHP-Prozess, **bevor** der ReactPHP Event Loop überhaupt startet.

**Symptom:** Promise Timeouts feuern nie, weil der Event Loop eingefroren ist.

**Root Cause:** Zeile 459 in `AsyncCloudClient.php`:
```php
$this->connection->authenticateOnly();  // ← BLOCKIERT DEN PROZESS!
```

## Lösung

Vollständig asynchrone Implementierung mit `React\Http\Browser` für alle HTTP-Calls.

## Betroffene Methoden in AsyncCloudClient

### 1. `authenticate()` (Zeile 447-474)
**Aktuell:**
- Erstellt `Connection`-Objekt
- Ruft `Connection::authenticateOnly()` auf (synchron, blockierend)
- Wrapped in try/catch mit Promise resolve/reject

**NEU:** Vollständig async mit `React\Http\Browser`
- Stage 1: Anonymous Token
- Stage 2: Login Token
- Stage 3: MQTT Token
- Alles als Promise Chain

### 2. `discoverDevices()` (Zeile 623-640)
**Aktuell:**
- Ruft `Connection::getDevices()` auf (synchron, blockierend cURL)

**NEU:** Async HTTP Request mit `React\Http\Browser`

## Zu portierende Logik aus Connection.php

### Stage 1: Anonymous Token (s1_*)
**Zeilen 211-342**

**Request:**
```php
{
  "method": "serverless.auth.user.anonymousAuthorize",
  "params": "{}",
  "spaceId": "mp-6c382a98-49b8-40ba-b761-645d83e8ee74",
  "timestamp": <milliseconds>
}
```

**Headers:**
- `Content-Type: application/json`
- `x-serverless-sign: <HMAC-MD5 signature>`

**Response Parsing:**
- `$response['data']['accessToken']`
- `$response['data']['expiresInSecond']` (10 Minuten = 600s)

**Signature Generation:** `generateSignature()` (Zeile 224-237)
- Sort keys alphabetically
- Filter empty values
- Create query string: `key1=val1&key2=val2`
- HMAC-MD5 with `CLIENT_SECRET`

### Stage 2: Login Token (s2_*)
**Zeilen 377-514**

**Request:**
```php
{
  "method": "serverless.function.runtime.invoke",
  "params": {
    "functionTarget": "router",
    "functionArgs": {
      "$url": "user/pub/login",
      "data": {
        "locale": "en",
        "username": "<email>",
        "password": "<password>"
      },
      "clientInfo": <DeviceInfo>,
      "uniIdToken": "<anonymous_token>"
    }
  },
  "spaceId": "...",
  "timestamp": <ms>,
  "token": "<anonymous_token>"
}
```

**Response Parsing:**
- `$response['data']['token']`
- `$response['data']['tokenExpired']` (Timestamp, ~14 Jahre gültig)

### Stage 3: MQTT Token (s3_*)
**Zeilen 544-677**

**Request:**
```php
{
  "method": "serverless.function.runtime.invoke",
  "params": {
    "functionTarget": "router",
    "functionArgs": {
      "$url": "common/emqx.getAccessToken",
      "data": {"locale": "en"},
      "clientInfo": <DeviceInfo>,
      "uniIdToken": "<login_token>"
    }
  },
  "spaceId": "...",
  "timestamp": <ms>,
  "token": "<anonymous_token>"
}
```

**Response Parsing:**
- `$response['data']['access_token']` (JWT)
- JWT `exp` claim (~3 Tage gültig)

### Device Discovery
**Zeilen 1101-1220**

**Request:**
```php
{
  "method": "serverless.function.runtime.invoke",
  "params": {
    "functionTarget": "router",
    "functionArgs": {
      "$url": "device/list",
      "data": {
        "locale": "en",
        "pageSize": 20,
        "pageNo": 1
      },
      "clientInfo": <DeviceInfo>,
      "uniIdToken": "<login_token>"
    }
  },
  "spaceId": "...",
  "timestamp": <ms>,
  "token": "<anonymous_token>"
}
```

**Response Parsing:**
- `$response['data']['rows']` (Array von Device-Daten)
- Jedes Device wird zu `Device`-Objekt konvertiert

## Benötigte Helper

### 1. `generateSignature(array $data): string`
- Aus `Connection::generateSignature()` (Zeile 224)
- HMAC-MD5 mit CLIENT_SECRET

### 2. `generateDeviceId(): string`
- Aus `Connection::generateDeviceId()` (nicht in Leseliste)
- 32-char hex string

### 3. `DeviceInfo` Value Object
- Bereits vorhanden in `src/ValueObject/`

### 4. HTTP Error Handling
- HTTP Status Codes (401, 403, 429, 500, etc.)
- cURL-ähnliches Error Mapping

## Implementierungs-Reihenfolge

1. **Helper-Methoden** in `AsyncCloudClient` hinzufügen:
   - `generateSignature()`
   - `generateDeviceId()`
   - `createBrowser(): Browser`

2. **Stage 1**: `stage1_getAnonymousToken(Browser $browser): PromiseInterface`
   - Request bauen
   - Signatur generieren
   - POST via Browser
   - Response parsen
   - Token + Expiry zurückgeben

3. **Stage 2**: `stage2_login(Browser $browser, string $anonToken): PromiseInterface`
   - Request mit AnonymousToken bauen
   - Response parsen

4. **Stage 3**: `stage3_getMqttToken(Browser $browser, string $anonToken, string $loginToken): PromiseInterface`
   - Request mit beiden Tokens bauen
   - JWT parsen für Expiry

5. **Device Discovery**: `fetchDevices(Browser $browser, string $anonToken, string $loginToken): PromiseInterface`
   - Device List Request
   - Parse zu `Device[]`

6. **Neue `authenticate()` Methode**:
   ```php
   private function authenticate(): PromiseInterface
   {
       $browser = $this->createBrowser();

       return $this->stage1_getAnonymousToken($browser)
           ->then(fn($anonToken) =>
               $this->stage2_login($browser, $anonToken)
                   ->then(fn($loginToken) => [$anonToken, $loginToken])
           )
           ->then(fn($tokens) =>
               $this->stage3_getMqttToken($browser, $tokens[0], $tokens[1])
                   ->then(fn($mqttToken) => [...$tokens, $mqttToken])
           )
           ->then(function($tokens) {
               [$anonToken, $loginToken, $mqttToken] = $tokens;
               // Store tokens in class properties
               $this->anonymousToken = $anonToken;
               $this->loginToken = $loginToken;
               $this->mqttTokenExpiresAt = $this->extractJwtExpiry($mqttToken);
           });
   }
   ```

7. **Neue `discoverDevices()` Methode**:
   ```php
   private function discoverDevices(): PromiseInterface
   {
       $browser = $this->createBrowser();

       return $this->fetchDevices(
           $browser,
           $this->anonymousToken,
           $this->loginToken
       )->then(function($devices) {
           $this->devices = $devices;
           $this->logger->info('Devices discovered', [
               'count' => count($devices)
           ]);
       });
   }
   ```

## Testing Strategy

1. **Unit Tests**: Einzelne Stage-Methoden mit Mock Browser
2. **Integration Test**: Vollständige authenticate() Chain gegen echte API
3. **Junior Tester**: End-to-End Test mit echtem Device

## Breaking Changes

**KEINE!** Die öffentliche API von `AsyncCloudClient` bleibt identisch:
- `connect(): PromiseInterface`
- `disconnect(): PromiseInterface`
- `publish(string $topic, string $payload): void`
- `subscribe(string $topic): void`

Nur die internen Implementierungen von `authenticate()` und `discoverDevices()` ändern sich.

## Abhängigkeiten

- ✅ `react/http` (installiert in Commit 955ac76)
- ✅ `react/promise` (bereits vorhanden)
- ✅ `react/event-loop` (bereits vorhanden)

## Erwartete Vorteile

1. **Non-blocking**: Event Loop läuft während HTTP-Calls
2. **Timeout funktioniert**: Timer werden ausgeführt
3. **Bessere Parallelisierung**: Mehrere Accounts gleichzeitig
4. **Konsistente Architektur**: Durchgehend async
