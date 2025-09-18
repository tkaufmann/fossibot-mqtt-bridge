## 🔧 API Implementation Guide

For developers who want to implement the Fossibot/Sydpower API in other languages, here's the complete protocol documentation:

### Authentication Flow (3-Stage Process)

The API uses a 3-stage authentication process:
```
1. Anonymous Token → 2. Login Token → 3. MQTT Token
```

#### Critical Constants
```python
ENDPOINT = "https://api.next.bspapp.com/client"
CLIENT_SECRET = "5rCEdl/nx7IgViBe4QYRiQ=="
SPACE_ID = "mp-6c382a98-49b8-40ba-b761-645d83e8ee74"

# MQTT WebSocket
MQTT_HOST_PROD = "mqtt.sydpower.com"
MQTT_HOST_DEV = "dev.mqtt.sydpower.com"
MQTT_PORT = 8083
MQTT_PASSWORD = "helloyou"  # Fixed constant
MQTT_WEBSOCKET_PATH = "/mqtt"
```

#### Stage 1: Anonymous Authorization
```http
POST https://api.next.bspapp.com/client
Content-Type: application/json
x-serverless-sign: <HMAC-MD5 signature>

{
  "method": "serverless.auth.user.anonymousAuthorize",
  "params": "{}",
  "spaceId": "mp-6c382a98-49b8-40ba-b761-645d83e8ee74",
  "timestamp": <milliseconds>
}
```

Response: `{"data": {"accessToken": "uni_id_token_xxx..."}}`

#### Stage 2: User Login
```http
POST https://api.next.bspapp.com/client
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
      "clientInfo": <device_info_object>
    }
  },
  "spaceId": "mp-6c382a98-49b8-40ba-b761-645d83e8ee74",
  "timestamp": <milliseconds>,
  "token": "<anonymous_token>"
}
```

#### Stage 3: MQTT Token
```http
POST https://api.next.bspapp.com/client
{
  "method": "serverless.function.runtime.invoke",
  "params": {
    "functionTarget": "router",
    "functionArgs": {
      "$url": "common/emqx.getAccessToken",
      "data": {"locale": "en"},
      "clientInfo": <device_info>,
      "uniIdToken": "<access_token>"
    }
  },
  "token": "<anonymous_token>"
}
```

### Request Signing (Critical!)

Every API call must be signed with HMAC-MD5:

```python
def generate_signature(data_dict):
    # 1. Sort keys alphabetically, filter empty values
    items = []
    for key in sorted(data_dict.keys()):
        if data_dict[key]:  # Only non-empty values
            items.append(f"{key}={data_dict[key]}")

    # 2. Create query string
    query_string = "&".join(items)

    # 3. HMAC-MD5 with CLIENT_SECRET
    signature = hmac.new(
        "5rCEdl/nx7IgViBe4QYRiQ==".encode('utf-8'),
        query_string.encode('utf-8'),
        hashlib.md5
    ).hexdigest()

    return signature
```

Header: `"x-serverless-sign": signature`

### Device Info Object (Android Emulation)

```json
{
  "PLATFORM": "app",
  "OS": "android",
  "APPID": "__UNI__55F5E7F",
  "DEVICEID": "<32-char-hex>",
  "channel": "google",
  "scene": 1001,
  "appName": "BrightEMS",
  "appVersion": "1.2.3",
  "deviceBrand": "Samsung",
  "deviceModel": "SM-A426B",
  "deviceType": "phone",
  "osName": "android",
  "osVersion": 10,
  "ua": "Mozilla/5.0 (Linux; Android 10; SM-A426B) AppleWebKit/537.36...",
  "locale": "en"
}
```

### MQTT WebSocket Communication

#### Connection Setup
```python
# Generate unique client ID
hex_string = ''.join(random.choice("0123456789abcdef") for _ in range(24))
timestamp_ms = int(time.time() * 1000)
client_id = f"client_{hex_string}_{timestamp_ms}"

# MQTT over WebSocket
client = mqtt.Client(
    client_id=client_id,
    transport="websockets",
    protocol=mqtt.MQTTv311
)

client.ws_set_options(
    path="/mqtt",
    headers={"Sec-WebSocket-Protocol": "mqtt"}
)

# Authentication: MQTT token as username, "helloyou" as password
client.username_pw_set(mqtt_token, "helloyou")
client.connect("mqtt.sydpower.com", 8083, keepalive=30)
```

#### Topic Patterns
- **Publish to**: `{device_mac}/client/request/data`
- **Subscribe to**: `{device_mac}/device/response/state`
- **Subscribe to**: `{device_mac}/device/response/client/+`

Note: Device MAC addresses are used without colons (e.g., `aabbccddeeff` instead of `aa:bb:cc:dd:ee:ff`)

### Modbus Commands

#### Command Structure
```python
def build_modbus_command(address, function, register, value=None):
    # Function 3: Read Holding Registers
    # Function 6: Write Single Register

    cmd = [address, function, reg_high, reg_low, val_high, val_low]

    # CRC-16 Modbus checksum
    crc = calculate_crc16(cmd)
    cmd.extend([crc_high, crc_low])  # High byte first

    return cmd
```

#### Pre-defined Commands
```python
# Register addresses
REGISTER_MODBUS_ADDRESS = 17
REGISTER_USB_OUTPUT = 24
REGISTER_DC_OUTPUT = 25
REGISTER_AC_OUTPUT = 26
REGISTER_LED = 27
REGISTER_STATE_OF_CHARGE = 56

# Common commands
REG_REQUEST_SETTINGS = [17, 3, 0, 0, 0, 80, <crc>]  # Read 80 registers
REG_ENABLE_USB = [17, 6, 0, 24, 0, 1, <crc>]        # Enable USB output
REG_DISABLE_USB = [17, 6, 0, 24, 0, 0, <crc>]       # Disable USB output
```

### Command Response Patterns

**⚠️ CRITICAL**: Different command types have different response behaviors!

#### Immediate Response Commands (Output Controls)
These commands trigger instant device responses via `{device_mac}/device/response/client/04`:

```python
# Commands that respond immediately:
- USB Output (Register 24) → Bit 6 in Register 41 bitfield
- DC Output (Register 25)  → Bit 5 in Register 41 bitfield
- AC Output (Register 26)  → Bit 4 in Register 41 bitfield
- LED Output (Register 27) → Bit 3 in Register 41 bitfield

# Example: Send "Enable USB" → Device immediately publishes updated bitfield
```

#### Delayed/No Response Commands (Settings)
These commands do NOT trigger immediate responses:

```python
# Commands that may not respond immediately:
- Maximum Charging Current (Register 20) → Only visible in periodic updates
- AC Silent Charging (Register 57)       → Only visible in periodic updates
- Standby Times (Registers 59, 60)       → Only visible in periodic updates

# These values appear in {device_mac}/device/response/client/data topic
# or during next periodic status update (every ~30 seconds)
```

#### Implementation Impact
```python
async def send_output_command(device_id, command):
    """Output commands - expect immediate feedback"""
    client.publish(f"{device_id}/client/request/data", command_bytes)

    # Wait for immediate response on client/04 topic
    await wait_for_topic_update(f"{device_id}/device/response/client/04", timeout=5)
    return parse_bitfield_response()

async def send_settings_command(device_id, command):
    """Settings commands - no immediate feedback"""
    client.publish(f"{device_id}/client/request/data", command_bytes)

    # Don't wait for immediate response - settings take effect gradually
    # Value will appear in next periodic update or explicit data request
    return True  # Command sent successfully
```

### Data Parsing

Device responses contain 81 registers. Key data points:

```python
def parse_device_data(registers):
    # Battery State of Charge (Register 56)
    soc_percent = round(registers[56] / 1000 * 100, 1)

    # Power values
    dc_input = registers[4]      # DC Input Power (W)
    total_input = registers[6]   # Total Input Power (W)
    total_output = registers[39] # Total Output Power (W)

    # Output states (Register 41 - Bitfield)
    outputs = registers[41]
    binary_str = format(outputs, '016b')

    usb_output = bool(int(binary_str[-1]))    # Bit 0
    dc_output = bool(int(binary_str[-2]))     # Bit 1
    ac_output = bool(int(binary_str[-3]))     # Bit 2
    led_output = bool(int(binary_str[-4]))    # Bit 3

    return {
        "soc": soc_percent,
        "dcInput": dc_input,
        "totalInput": total_input,
        "totalOutput": total_output,
        "usbOutput": usb_output,
        "dcOutput": dc_output,
        "acOutput": ac_output,
        "ledOutput": led_output
    }
```

### Critical Implementation Notes

1. **Device ID**: Generate new 32-char hex string for each session
   ```python
   # ❌ WRONG: Reusing static device ID
   device_id = "12345678901234567890123456789012"

   # ✅ RIGHT: Generate new random ID each time
   device_id = "".join(random.choice("0123456789ABCDEF") for _ in range(32))
   ```

2. **Signature**: Sort keys alphabetically, filter empty values
   ```python
   # ❌ WRONG: Include empty values or wrong order
   items = [f"{k}={v}" for k, v in data.items()]

   # ✅ RIGHT: Sort alphabetically, filter empty values
   items = []
   for key in sorted(data.keys()):
       if data[key]:  # Only non-empty values
           items.append(f"{key}={data[key]}")
   ```

3. **Params**: Serialize as JSON string for `function.runtime.invoke`
   ```python
   # ❌ WRONG: Pass params as object
   data = {
       "method": "serverless.function.runtime.invoke",
       "params": {"functionTarget": "router", ...}  # Object
   }

   # ✅ RIGHT: Serialize params as JSON string
   data = {
       "method": "serverless.function.runtime.invoke",
       "params": json.dumps({"functionTarget": "router", ...})  # String
   }
   ```

4. **MAC addresses**: Remove colons for MQTT topics
   ```python
   # ❌ WRONG: Use MAC with colons in MQTT topics
   topic = f"aa:bb:cc:dd:ee:ff/client/request/data"

   # ✅ RIGHT: Remove colons from device_id
   device_mac = device.get("device_id", "").replace(":", "")  # aabbccddeeff
   topic = f"{device_mac}/client/request/data"
   ```

5. **MQTT auth**: Token as username, "helloyou" as password
   ```python
   # ❌ WRONG: Use access token as password
   client.username_pw_set(access_token, mqtt_token)

   # ✅ RIGHT: MQTT token as username, fixed password
   client.username_pw_set(mqtt_token, "helloyou")
   ```

6. **CRC**: Append high byte first in Modbus commands
   ```python
   # ❌ WRONG: Append CRC low-high
   crc = calculate_crc(cmd)
   cmd.extend([crc_low, crc_high])

   # ✅ RIGHT: CRC is appended high-low (same as other 16-bit values)
   crc = calculate_crc(cmd)
   cmd.extend([crc_high, crc_low])
   ```

7. **Bitfield**: Read Register 41 from right to left
   ```python
   # ❌ WRONG: Read binary string from left
   binary_str = format(registers[41], '016b')
   usb_output = bool(int(binary_str[0]))  # First bit

   # ✅ RIGHT: Read binary string from right (LSB first)
   binary_str = format(registers[41], '016b')
   usb_output = bool(int(binary_str[-1]))  # Bit 0 (rightmost)
   dc_output = bool(int(binary_str[-2]))   # Bit 1
   ac_output = bool(int(binary_str[-3]))   # Bit 2
   ```

8. **MQTT Client ID**: Use specific format with timestamp
   ```python
   # ❌ WRONG: Simple random client ID
   client_id = "myclient_12345"

   # ✅ RIGHT: App-compatible format
   hex_string = ''.join(random.choice("0123456789abcdef") for _ in range(24))
   timestamp_ms = int(time.time() * 1000)
   client_id = f"client_{hex_string}_{timestamp_ms}"
   ```

### Critical Parameter Constraints

These parameters MUST NOT have certain values or the API will fail:

> **⚠️ IMPLEMENTATION RECOMMENDATION**: Always implement these validations as defensive programming practices. Use guard clauses, assertions, or input validation functions to catch these issues early in development rather than debugging mysterious API failures later.

#### API Response Validation
```python
# ❌ WRONG: Assuming HTTP 200 means success
if response.status == 200:
    return response.json()

# ✅ RIGHT: Always validate data field exists
if response.status != 200:
    raise Exception(f"HTTP {response.status}")
resp_json = await response.json()
if not resp_json.get('data'):
    raise Exception(f"API failed: {resp_json}")
```

#### Token Validation
```python
# ❌ WRONG: Not checking for None/empty tokens
access_token = response.get('data', {}).get('token')
# Proceed without validation

# ✅ RIGHT: Validate all tokens are non-empty
auth_token = response.get('data', {}).get('accessToken')
if not auth_token:
    raise ValueError("Failed to get anonymous auth token")

access_token = response.get('data', {}).get('token')
if not access_token:
    raise ValueError("Failed to get access token from login response")
```

#### MQTT Connection Codes
```python
# ❌ WRONG: Ignoring MQTT connection result codes
def on_connect(client, userdata, flags, rc):
    print("Connected!")  # Always assume success

# ✅ RIGHT: Check result code - only 0 means success
def on_connect(client, userdata, flags, rc):
    if rc != 0:
        errors = {
            1: "Incorrect protocol version",
            2: "Invalid client identifier",
            3: "Server unavailable",
            4: "Bad username or password",
            5: "Not authorized"
        }
        raise Exception(f"MQTT failed: {errors.get(rc, f'Unknown: {rc}')}")
```

#### Register Count Validation
```python
# ❌ WRONG: Processing without validating register count
def parse_device_data(registers):
    soc = registers[56]  # May crash if not enough registers

# ✅ RIGHT: Validate exactly 81 registers
def parse_device_data(registers):
    if len(registers) != 81:
        raise Exception(f"Invalid register count: {len(registers)}, expected 81")
    soc = round(registers[56] / 1000 * 100, 1)
```

#### Device List Validation
```python
# ❌ WRONG: Not checking if any devices returned
devices = api_response.get('data', {}).get('rows', [])
for device in devices:  # May be empty array

# ✅ RIGHT: Validate device list is not empty
devices = api_response.get('data', {}).get('rows', [])
if not devices:
    raise ValueError("No devices returned from API")
```

#### MQTT Connection State
```python
# ❌ WRONG: Sending commands without checking connection
client.publish(topic, data)

# ✅ RIGHT: Always verify MQTT is connected first
if not client or not client.is_connected():
    raise RuntimeError("MQTT client not connected")
client.publish(topic, data)
```

#### Defensive Programming Example
```python
def validate_api_response(response_json, expected_fields=None):
    """Centralized API response validation"""
    if not response_json.get('data'):
        raise Exception(f"API response missing 'data' field: {response_json}")

    if expected_fields:
        for field in expected_fields:
            if not response_json['data'].get(field):
                raise Exception(f"Missing required field '{field}' in API response")

    return response_json['data']

def ensure_mqtt_connected(client):
    """Guard clause for MQTT operations"""
    if not client or not client.is_connected():
        raise RuntimeError("MQTT client not connected - call connect() first")

# Usage:
auth_data = validate_api_response(auth_response, ['accessToken'])
ensure_mqtt_connected(mqtt_client)
mqtt_client.publish(topic, data)
```

### Performance & Reliability Settings

#### Timeout Configuration
```python
# API Timeouts (critical for stability)
HTTP_SESSION_TIMEOUT = 15  # aiohttp session timeout
HTTP_REQUEST_TIMEOUT = 10  # individual request timeout
AUTH_TIMEOUT = 30          # authentication process
MQTT_TOKEN_TIMEOUT = 15    # MQTT token request
DEVICE_LIST_TIMEOUT = 15   # device discovery
MQTT_CONNECT_TIMEOUT = 30  # MQTT connection establishment
DATA_UPDATE_TIMEOUT = 30   # waiting for device data

# Connection Settings
MQTT_KEEPALIVE = 30        # MQTT keepalive interval
MQTT_QOS = 1              # Quality of Service for commands
MQTT_CLEAN_SESSION = True  # Start fresh session each time
```

#### Retry Logic
```python
# API Retry Strategy
MAX_API_RETRIES = 3
RETRY_BASE_DELAY = 2  # seconds, multiplied by attempt number

# Reconnection Strategy
MAX_RECONNECTION_ATTEMPTS = 5
RECONNECTION_BASE_DELAY = 3
RECONNECTION_MAX_DELAY = 30
# Formula: min(base_delay * (1.5 ** attempt), max_delay)

# Example: 3s, 4.5s, 6.8s, 10.1s, 15.2s, then cap at 30s
```

#### Required Headers
```python
# HTTP Headers (mandatory)
headers = {
    "Content-Type": "application/json",
    "x-serverless-sign": "<signature>",
    "user-agent": "<android_user_agent>"  # Must match device info
}

# MQTT WebSocket Headers (mandatory)
ws_headers = {
    "Sec-WebSocket-Protocol": "mqtt"  # Required for WebSocket upgrade
}
```

### Advanced Implementation Details

#### Message Deduplication
```python
# MQTT can send duplicate messages - implement deduplication
message_cache = {}
MESSAGE_CACHE_TTL = 2  # seconds

def process_mqtt_message(topic, payload):
    message_id = f"{topic}:{hash(bytes(payload))}"
    current_time = time.time()

    # Skip if seen recently
    if message_id in message_cache:
        if current_time - message_cache[message_id] < MESSAGE_CACHE_TTL:
            return  # Skip duplicate

    message_cache[message_id] = current_time
    # Process message...
```

#### Thread Safety
```python
# MQTT callbacks run in separate threads - use proper locking
import threading
import asyncio

class SafeMQTTHandler:
    def __init__(self, event_loop):
        self.loop = event_loop
        self.cache_lock = threading.RLock()  # For MQTT callbacks
        self.data_lock = asyncio.Lock()      # For async operations

    def mqtt_callback(self, client, userdata, message):
        """Runs in MQTT thread"""
        with self.cache_lock:
            # Thread-safe operations
            self.loop.call_soon_threadsafe(self.process_async, message)
```

#### Connection Management
```python
# Prevent connection race conditions
connection_lock = asyncio.Lock()
MIN_RECONNECTION_INTERVAL = 5  # seconds between attempts

async def connect_with_protection():
    # Timeout-protected lock acquisition
    try:
        async with asyncio.wait_for(connection_lock.acquire(), timeout=10):
            # Connection logic here
            pass
    except asyncio.TimeoutError:
        raise Exception("Connection lock timeout - possible deadlock")
```

#### Developer Mode Support
```python
# Production vs Development endpoints
class APIClient:
    def __init__(self, developer_mode=False):
        self.mqtt_host = (
            "dev.mqtt.sydpower.com" if developer_mode
            else "mqtt.sydpower.com"
        )

# Usage: Enable developer_mode for testing against dev servers
```

#### MQTT Protocol Specifics
```python
# Critical MQTT settings for compatibility
mqtt_client = mqtt.Client(
    protocol=mqtt.MQTTv311,      # Must use v3.1.1, NOT v5.0
    clean_session=True,          # Don't persist session state
    transport="websockets"       # Required for cloud connection
)

# WebSocket headers are mandatory
mqtt_client.ws_set_options(
    path="/mqtt",
    headers={"Sec-WebSocket-Protocol": "mqtt"}
)

# Start network loop BEFORE waiting for connection
mqtt_client.loop_start()
await wait_for_connection()  # Then wait
```

#### Bitfield Parsing Gotcha
```python
# Register 41 bitfield parsing - position matters!
def parse_outputs(register_41_value):
    # Convert to 16-bit binary string
    binary_str = format(register_41_value, '016b')

    # ⚠️ CRITICAL: Index from RIGHT (LSB first)
    # JavaScript equivalent: binary_str.slice(-16)
    outputs = {
        "usbOutput": binary_str[6] == '1',    # Bit position 6
        "dcOutput": binary_str[5] == '1',     # Bit position 5
        "acOutput": binary_str[4] == '1',     # Bit position 4
        "ledOutput": binary_str[3] == '1'     # Bit position 3
    }
    return outputs
```

#### Rate Limiting Protection
```python
# Prevent API abuse with minimum intervals
last_reconnection = 0
MIN_RECONNECTION_INTERVAL = 5

async def reconnect():
    global last_reconnection
    current_time = time.time()

    if current_time - last_reconnection < MIN_RECONNECTION_INTERVAL:
        sleep_time = MIN_RECONNECTION_INTERVAL - (current_time - last_reconnection)
        await asyncio.sleep(sleep_time)

    last_reconnection = time.time()
    # Proceed with reconnection...
```

#### CRC Calculation Details
```python
def calculate_crc16_modbus(data):
    """Calculate CRC-16 Modbus checksum"""
    crc = 0xFFFF
    for byte in data:
        crc ^= byte
        for _ in range(8):
            if crc & 1:
                crc = (crc >> 1) ^ 0xA001  # Modbus polynomial
            else:
                crc >>= 1
    return crc & 0xFFFF

def append_crc(command_bytes):
    """Append CRC to Modbus command"""
    crc = calculate_crc16_modbus(command_bytes)
    crc_high = (crc >> 8) & 0xFF
    crc_low = crc & 0xFF
    return command_bytes + [crc_high, crc_low]  # High byte first
```

### Error Handling

- **HTTP 200 ≠ Success**: Always check `data` field in response
- **Token Expiry**: Restart complete auth flow on 401/403
- **MQTT Disconnect**: Implement exponential backoff reconnection
- **No Data**: Trigger reconnection after 5 minutes of silence
- **Rate Limiting**: Server may return 429, implement delays

For complete implementation examples, see the source code in `custom_components/fossibot-ha/sydpower/`.

## Limitations

- Requires internet connectivity to work (cloud-based)
- Depends on the Fossibot/Sydpower cloud service being operational
- May be affected by changes to the Fossibot API or app
- Authentication tokens may expire periodically, requiring reconnection

## Credits

This integration was created by leveraging code developed by iamslan, based on analyzing the BrightEMS app's communication patterns. The original reverse engineering work and analysis of the API was performed by iamslan.

## License

This project is licensed under the MIT License - see the LICENSE file for details.
