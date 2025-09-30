#!/usr/bin/env python3
"""
ABOUTME: Python MQTT Client Beispiel für Fossibot Bridge

Simpler Python-Client zur Gerätesteuerung und -überwachung.
Benötigt: paho-mqtt (pip install paho-mqtt)
"""

import json
import time
import paho.mqtt.client as mqtt

MQTT_BROKER = "localhost"
MQTT_PORT = 1883
# WICHTIG: Ersetze mit deiner Geräte-MAC
DEVICE_MAC = "7C2C67AB5F0E"

# Callbacks
def on_connect(client, userdata, flags, rc):
    print(f"Connected with result code {rc}")

    # Subscribe zu Device State
    client.subscribe(f"fossibot/{DEVICE_MAC}/state")
    client.subscribe(f"fossibot/{DEVICE_MAC}/availability")
    client.subscribe("fossibot/bridge/status")

    print("Subscribed to topics")

def on_message(client, userdata, msg):
    topic = msg.topic

    if topic.endswith("/state"):
        state = json.loads(msg.payload)
        print(f"\n📊 Device State Update:")
        print(f"  Battery: {state['soc']}%")
        print(f"  USB: {'ON' if state['usbOutput'] else 'OFF'}")
        print(f"  AC: {'ON' if state['acOutput'] else 'OFF'}")
        print(f"  Time: {state['timestamp']}")

    elif topic.endswith("/availability"):
        status = msg.payload.decode()
        print(f"\n🔌 Device: {status}")

    elif topic == "fossibot/bridge/status":
        status = json.loads(msg.payload)
        print(f"\n🌉 Bridge: {status['status']} (v{status['version']})")

# Client erstellen
client = mqtt.Client("fossibot_python_client")
client.on_connect = on_connect
client.on_message = on_message

# Verbinden
print(f"Connecting to {MQTT_BROKER}:{MQTT_PORT}...")
client.connect(MQTT_BROKER, MQTT_PORT, 60)

# Loop im Hintergrund starten
client.loop_start()

# Kommando-Funktionen
def turn_usb_on():
    command = json.dumps({"action": "usb_on"})
    client.publish(f"fossibot/{DEVICE_MAC}/command", command, qos=1)
    print("✅ Sent: USB ON")

def turn_usb_off():
    command = json.dumps({"action": "usb_off"})
    client.publish(f"fossibot/{DEVICE_MAC}/command", command, qos=1)
    print("✅ Sent: USB OFF")

def set_charging_current(amperes: int):
    if not 1 <= amperes <= 20:
        print("❌ Error: Amperes must be 1-20")
        return

    command = json.dumps({
        "action": "set_charging_current",
        "amperes": amperes
    })
    client.publish(f"fossibot/{DEVICE_MAC}/command", command, qos=1)
    print(f"✅ Sent: Set charging current to {amperes}A")

# Interaktives Menü
print("\n" + "="*50)
print("Fossibot Device Control - Python Beispiel")
print("="*50)

try:
    while True:
        print("\nCommands:")
        print("  1 - Turn USB ON")
        print("  2 - Turn USB OFF")
        print("  3 - Set charging current")
        print("  q - Quit")

        choice = input("\nEnter command: ").strip()

        if choice == "1":
            turn_usb_on()
        elif choice == "2":
            turn_usb_off()
        elif choice == "3":
            amperes = int(input("Enter amperes (1-20): "))
            set_charging_current(amperes)
        elif choice.lower() == "q":
            break
        else:
            print("Invalid command")

except KeyboardInterrupt:
    print("\n\nShutting down...")

finally:
    client.loop_stop()
    client.disconnect()
    print("Disconnected")
