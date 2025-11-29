<?php

/**
 * MQTT_Publish - Publish a payload to an MQTT topic without creating a dedicated MQTT Server Device
 *
 * Source: https://community.symcon.de/t/auf-mqtt-topic-publishen-leicht-gemacht/133273
 *
 * @param int $server_id The MQTT Server instance ID
 * @param string $topic The MQTT topic to publish to
 * @param mixed $payload The payload (array, string, int, float, bool)
 * @param bool $retain Whether to retain the message (default: false)
 * @param int|null $parent_id Optional parent instance ID for the temporary device (default: caller instance)
 * @return bool Success status
 */
function MQTT_Publish($server_id, $topic, $payload, $retain = false, $parent_id = null) {
    // ensure server instance exists
    if(!IPS_InstanceExists($server_id)) {
        trigger_error("MQTT_Publish: MQTT Client Instance {$server_id} does not exist", E_USER_WARNING);
        return false;
    }

    // convert array structure to json string
    if(is_array($payload)) $payload = json_encode($payload);

    // determine data type
    if(is_string($payload)) {
        $ips_var_type = 3;
    } else if(is_float($payload)) {
        $ips_var_type = 2;
    } else if(is_int($payload)) {
        $ips_var_type = 1;
    } else if(is_bool($payload)) {
        $ips_var_type = 0;
    } else { // unsupported
        return false;
    }

    $module_id = "{01C00ADD-D04E-452E-B66A-D253278743FE}" /* Module ID of MQTT Server Device */;
    $ident = "TempMQTTDevice";

    // Determine parent: use provided parent_id or caller's instance
    $parent = $parent_id !== null ? $parent_id : $_IPS['SELF'];

    // enter semaphore to ensure the temporary device gets used by one thread at a time
    if(IPS_SemaphoreEnter($ident, 100)) {
        // get temporary MQTT Server Device or create if needed
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        $justCreated = false;
        if($id === false) {
            $id = @IPS_CreateInstance($module_id);
            if($id === false) {
                trigger_error("MQTT_Publish: Failed to create temporary MQTT Server Device instance", E_USER_WARNING);
                IPS_SemaphoreLeave($ident);
                return false;
            }
            IPS_SetParent($id, $parent);
            IPS_SetIdent($id, $ident);
            $justCreated = true;
        }

        // ensure the specified server instance is actually compatible
        if(!IPS_IsInstanceCompatible($id, $server_id)) {
            trigger_error("MQTT_Publish: MQTT Server Device not compatible with MQTT Client {$server_id}", E_USER_WARNING);
            IPS_SemaphoreLeave($ident);
            return false;
        }

        // ensure that the temporary device is actually connected to the correct server instance
        $inst_config = IPS_GetInstance($id);
        if($inst_config["ConnectionID"] != $server_id) {
            IPS_DisconnectInstance($id);
            if(!@IPS_ConnectInstance($id, $server_id)) {
                trigger_error("MQTT_Publish: Failed to connect temporary device to MQTT Client {$server_id}", E_USER_WARNING);
                IPS_SemaphoreLeave($ident);
                return false;
            }
        }

        // name object to help with debugging (only on creation to avoid log spam)
        if($justCreated) {
            IPS_SetName($id, "MQTT Command Publisher (automatisch generiert)");
        }

        // configure temporary device
        $config_arr = array(
            "Retain" => $retain,
            "Topic" => $topic,
            "Type" => $ips_var_type
        );
        $config_str = json_encode($config_arr);
        IPS_SetConfiguration($id, $config_str);
        IPS_ApplyChanges($id);

        // get Value variable and use it to publish the payload
        $var_id = @IPS_GetObjectIDByIdent("Value", $id);
        RequestAction($var_id, $payload);

        IPS_SemaphoreLeave($ident);
    } else { // semaphore timeout
        trigger_error("MQTT_Publish: Semaphore timeout after 2000ms", E_USER_WARNING);
        return false;
    }

    return true;
}
