<?php
################################################################################
# Name: OpenPanel WHMCS Module
# Usage: https://openpanel.com/docs/articles/extensions/openpanel-and-whmcs/
# Source: https://github.com/stefanpejcic/openpanel-whmcs-module
# Author: Stefan Pejcic
# Created: 01.05.2024
# Last Modified: 08.10.2024
# Company: openpanel.com
# Copyright (c) openpanel.com
# 
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
# 
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
# 
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
# THE SOFTWARE.
################################################################################


############### CORE STUFF ##################
# BASIC AUTH, SHOULD BE REUSED IN ALL ROUTES
function getApiProtocol($hostname) {
    return filter_var($hostname, FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
}

function getAuthToken($params) {
    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        $token = false;
        $error = "cURL Error: " . curl_error($curl);
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $token = isset($responseData['access_token']) ? $responseData['access_token'] : false;
        $error = $token ? null : "Token not found in response";
    }

    // Close cURL session
    curl_close($curl);

    return array($token, $error);
}

function apiRequest($endpoint, $token, $data = null, $method = 'POST') {
    // Prepare cURL request
    $curl = curl_init();
    $options = array(
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ),
    );

    if ($method === 'POST' && $data !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    } elseif ($method === 'DELETE') {
        $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    }

    curl_setopt_array($curl, $options);

    // Execute cURL request
    $response = curl_exec($curl);

    // Decode the response JSON
    $responseData = json_decode($response, true);

    // Check for errors
    if (curl_errno($curl)) {
        $result = array("success" => false, "message" => "cURL Error: " . curl_error($curl));
    } elseif ($responseData && isset($responseData['response']['message'])) {
        $result = array("success" => true, "message" => $responseData['response']['message']);
    } else {
        $result = array("success" => false, "message" => "API request failed");
    }

    // Close cURL session
    curl_close($curl);

    return $result;
}







############### USER ACTIONS ################
# CREATE ACCOUNT
function openpanel_CreateAccount($params) {
    list($jwtToken, $error) = getAuthToken($params);

    if (!$jwtToken) {
        return json_encode(array("success" => false, "message" => $error));
    }
    
    try {
        $apiProtocol = getApiProtocol($params["serverhostname"]);
        $createUserEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users';
        $packageId = $params['pid'];  // Get the Product ID (Package ID)
        
        // Query the database to get the package name
        $result = select_query("tblproducts", "name", array("id" => $packageId));
        $data = mysql_fetch_array($result);
        $packageName = $data['name'];  // This is the package name
    
        // Prepare data for user creation
        $userData = array(
            'username' => $params["username"],
            'password' => $params["password"],
            'email' => $params["clientsdetails"]["email"],
            'plan_name' => $packageName
        );
    
        // Make API request to create user
        $response = apiRequest($createUserEndpoint, $jwtToken, $userData);
        // Decode the JSON response
        $decodedResponse = json_decode($response, true);
    
        if (isset($decodedResponse['success']) && $decodedResponse['success'] === true) {
            return 'success';
        } else {
            return isset($decodedResponse['error']) ? $decodedResponse['error'] : 'An unknown error occurred.';
        }
    
    } catch (Exception $e) {
        logModuleCall(
            'provisioningmodule',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
    
        return $e->getMessage();
    }
    
    return 'success';
}

# TERMINATE ACCOUNT
function openpanel_TerminateAccount($params) {
    list($jwtToken, $error) = getAuthToken($params);

    if (!$jwtToken) {
        return json_encode(array("success" => false, "message" => $error));
    }

    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $terminateUserEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

    // Make API request to terminate user
    return json_encode(apiRequest($terminateUserEndpoint, $jwtToken, null, 'DELETE'));
}


# CHANGE PASSWORD FOR ACCOUNT
function openpanel_ChangePassword($params) {
    list($jwtToken, $error) = getAuthToken($params);

    if (!$jwtToken) {
        return json_encode(array("success" => false, "message" => $error));
    }

    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $changePasswordEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

    // Prepare data for password change
    $passwordData = array('password' => $params["password"]);

    // Make API request to change password
    return json_encode(apiRequest($changePasswordEndpoint, $jwtToken, $passwordData, 'PATCH'));
}


# SUSPEND ACCOUNT
function openpanel_SuspendAccount($params) {
    list($jwtToken, $error) = getAuthToken($params);

    if (!$jwtToken) {
        return json_encode(array("success" => false, "message" => $error));
    }

    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $suspendAccountEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

    // Prepare data for account suspension
    $suspendData = array('action' => 'suspend');

    // Make API request to suspend account
    return json_encode(apiRequest($suspendAccountEndpoint, $jwtToken, $suspendData, 'PATCH'));
}

# UNSUSPEND ACCOUNT
function openpanel_UnsuspendAccount($params) {
    list($jwtToken, $error) = getAuthToken($params);

    if (!$jwtToken) {
        return json_encode(array("success" => false, "message" => $error));
    }

    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $unsuspendAccountEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

    // Prepare data for account unsuspension
    $unsuspendData = array('action' => 'unsuspend');

    // Make API request to unsuspend account
    return json_encode(apiRequest($unsuspendAccountEndpoint, $jwtToken, $unsuspendData, 'PATCH'));
}


# CHANGE PACKAGE (PLAN)
function openpanel_ChangePackage($params) {
    list($jwtToken, $error) = getAuthToken($params);

    if (!$jwtToken) {
        return json_encode(array("success" => false, "message" => $error));
    }

    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $changePlanEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

    $packageId = $params['pid'];  // Get the Product ID (Package ID)
    
    // Query the database to get the package name
    $result = select_query("tblproducts", "name", array("id" => $packageId));
    $data = mysql_fetch_array($result);
    $packageName = $data['name'];  // This is the package name

    
    // Prepare data for changing plan
    $planData = array('plan_name' => $packageName);

    // Make API request to change plan
    return json_encode(apiRequest($changePlanEndpoint, $jwtToken, $planData, 'PUT'));
}



############### AUTOLOGIN LINKS ##############

# LOGIN FOR USERS ON FRONT
function openpanel_ClientArea($params) {
    list($jwtToken, $error) = getAuthToken($params);

    if (!$jwtToken) {
        return '<p>Error: ' . $error . '</p>';
    }

    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $getLoginLinkEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

    // Prepare data for login link generation
    $loginData = array();

    // Make API request to get login link
    $response = apiRequest($getLoginLinkEndpoint, $jwtToken, $loginData, 'CONNECT');

    if ($response["success"] && isset($response["link"])) {
        $code = '<script>
                    function loginOpenPanelButton() {
                        var openpanel_btn = document.getElementById("loginLink");
                        openpanel_btn.textContent = "Logging in...";
                        document.getElementById("refreshMessage").style.display = "block";
                    }
                </script>';
        $code .= '<a id="loginLink" class="btn btn-primary" href="' . $response["link"] . '" target="_blank" onclick="loginOpenPanelButton()">
                    <svg version="1.0" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 213.000000 215.000000" preserveAspectRatio="xMidYMid meet"><g transform="translate(0.000000,215.000000) scale(0.100000,-0.100000)" fill="currentColor" stroke="none"><path d="M990 2071 c-39 -13 -141 -66 -248 -129 -53 -32 -176 -103 -272 -158 -206 -117 -276 -177 -306 -264 -17 -50 -19 -88 -19 -460 0 -476 0 -474 94 -568 55 -56 124 -98 604 -369 169 -95 256 -104 384 -37 104 54 532 303 608 353 76 50 126 113 147 184 8 30 12 160 12 447 0 395 -1 406 -22 461 -34 85 -98 138 -317 264 -104 59 -237 136 -295 170 -153 90 -194 107 -275 111 -38 2 -81 0 -95 -5z m205 -561 c66 -38 166 -95 223 -127 l102 -58 0 -262 c0 -262 0 -263 -22 -276 -13 -8 -52 -31 -88 -51 -36 -21 -126 -72 -200 -115 l-135 -78 -3 261 -3 261 -166 95 c-91 52 -190 109 -219 125 -30 17 -52 34 -51 39 3 9 424 256 437 255 3 0 59 -31 125 -69z"></path></g></svg> &nbsp; Login to OpenPanel
                </a>';
        $code .= '<p id="refreshMessage" style="display: none;">One-time login link has already been used, please refresh the page to login again.</p>';
    } else {
        $code = '<p>Error: Unable to generate login link for OpenPanel. Please try again later.</p>';
        if (isset($response["message"])) {
            $code .= '<p>Server Response: ' . htmlentities($response["message"]) . '</p>';
        }
    }

    return $code;
}



# LOGIN FROM admin/configservers.php
function openpanel_AdminLink($params) {
    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $adminLoginEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/login';

    $code = '<form action="' . $adminLoginEndpoint . '" method="post" target="_blank">
            <input type="hidden" name="username" value="' . $params["serverusername"] . '" />
            <input type="hidden" name="password" value="' . $params["serverpassword"] . '" />
            <input type="submit" value="Login to OpenAdmin" />
            </form>';
    return $code;
}


# LOGIN FOR ADMINS FROM BACKEND
function openpanel_LoginLink($params) {
    list($jwtToken, $error) = getAuthToken($params);

    if (!$jwtToken) {
        return '<p>Error: ' . $error . '</p>';
    }

    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $getLoginLinkEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

    // Prepare data for login link generation
    $loginData = array();

    // Make API request to get login link
    $response = apiRequest($getLoginLinkEndpoint, $jwtToken, $loginData, 'CONNECT');

    if ($response["success"] && isset($response["link"])) {
        $code = '<script>
                    function loginOpenPanelButton() {
                        var openpanel_btn = document.getElementById("loginLink");
                        openpanel_btn.textContent = "Logging in...";
                        document.getElementById("refreshMessage").style.display = "block";
                    }
                </script>';
        $code .= '<a id="loginLink" class="btn btn-primary" href="' . $response["link"] . '" target="_blank" onclick="loginOpenPanelButton()">
                    <svg version="1.0" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 213.000000 215.000000" preserveAspectRatio="xMidYMid meet"><g transform="translate(0.000000,215.000000) scale(0.100000,-0.100000)" fill="currentColor" stroke="none"><path d="M990 2071 c-39 -13 -141 -66 -248 -129 -53 -32 -176 -103 -272 -158 -206 -117 -276 -177 -306 -264 -17 -50 -19 -88 -19 -460 0 -476 0 -474 94 -568 55 -56 124 -98 604 -369 169 -95 256 -104 384 -37 104 54 532 303 608 353 76 50 126 113 147 184 8 30 12 160 12 447 0 395 -1 406 -22 461 -34 85 -98 138 -317 264 -104 59 -237 136 -295 170 -153 90 -194 107 -275 111 -38 2 -81 0 -95 -5z m205 -561 c66 -38 166 -95 223 -127 l102 -58 0 -262 c0 -262 0 -263 -22 -276 -13 -8 -52 -31 -88 -51 -36 -21 -126 -72 -200 -115 l-135 -78 -3 261 -3 261 -166 95 c-91 52 -190 109 -219 125 -30 17 -52 34 -51 39 3 9 424 256 437 255 3 0 59 -31 125 -69z"></path></g></svg> &nbsp; Login to OpenPanel
                </a>';
        $code .= '<p id="refreshMessage" style="display: none;">One-time login link has already been used, please refresh the page to login again.</p>';
    } else {
        $code = '<p>Error: Unable to generate login link for OpenPanel. Please try again later.</p>';
        if (isset($response["message"])) {
            $code .= '<p>Server Response: ' . htmlentities($response["message"]) . '</p>';
        }
    }

    return $code;
}



############### MAINTENANCE ################


# TODO: GET USAGE FOR USERS!!!!!!!!
function openpanel_UsageUpdate($params) {

    # resposne should be formated like this:
    #{
    #    "disk_usage": "1024 MB",
    #    "disk_limit": "2048 MB",
    #    "bandwidth_usage": "512 MB",
    #    "bandwidth_limit": "1024 MB"
    #}

    $apiProtocol = getApiProtocol($params["serverhostname"]);
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Authenticate and get JWT token
    list($jwtToken, $error) = getAuthToken($params);

    if (!$jwtToken) {
        return json_encode(array(
            "success" => false,
            "message" => $error
        ));
    }

    // Prepare API endpoint for getting usage
    $getUsageEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/usage/';

    // Prepare cURL request for getting usage
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $getUsageEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer " . $jwtToken,
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request for getting usage
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        $result = json_encode(array(
            "success" => false,
            "message" => "cURL Error: " . curl_error($curl)
        ));
    } else {
        // Decode the response JSON
        $usageData = json_decode($response, true);

        // Loop through results and update database
        foreach ($usageData as $user => $values) {
            update_query("tblhosting", array(
                "diskusage" => $values['disk_usage'],
                "disklimit" => $values['disk_limit'],
                "lastupdate" => "now()"
            ), array("server" => $params['serverid'], "username" => $user));
        }

        $result = json_encode(array(
            "success" => true,
            "message" => "Usage updated successfully"
        ));
    }

    // Close cURL session
    curl_close($curl);

    return $result;
}


?>
