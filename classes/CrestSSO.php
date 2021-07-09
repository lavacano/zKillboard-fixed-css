<?php

use cvweiss\redistools\RedisTimeQueue;

// Borrowed very heavily from FuzzySteve <3 https://github.com/fuzzysteve/eve-sso-auth/
class CrestSSO
{
    public static $userAgent = 'zKillboard.com CREST SSO';

    // Redirect user to CREST login
    public static function login()
    {
        global $app, $redis, $ccpClientID;
        // https://sisilogin.testeveonline.com/ https://login.eveonline.com/

        $referrer = @$_SERVER['HTTP_REFERER'];
        if ($referrer == '') {
            $referrer = '/';
        }

        $charID = @$_SESSION['characterID'];
        $hash = @$_SESSION['characterHash'];

        if ($charID != null && $hash != null) {
            $value = $redis->get("login:$charID:$hash");
            if ($value == true) {
                $app->redirect($referrer, 302);
                exit();
            }
        }

        $factory = new \RandomLib\Factory;
        $generator = $factory->getGenerator(new \SecurityLib\Strength(\SecurityLib\Strength::MEDIUM));
        $state = $generator->generateString(128, "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
        $_SESSION['oauth2State'] = $state;

        $scopes = 'publicData';
        $requestedScopes = isset($_GET['scopes']) ? $_GET['scopes'] : [];
        if (in_array('esi-killmails.read_killmails.v1', $requestedScopes)) {
            $requestedScopes[] = 'esi-killmails.read_corporation_killmails.v1';
        }

        if (in_array('esi-universe.read_structures.v1', $requestedScopes)) {
            $requestedScopes[] = 'esi-universe.read_structures.v1';
            $requestedScopes[] = 'esi-corporations.read_structures.v1';
        }

        if (count($requestedScopes) > 0) {
            $scopes .= '+'.implode('+', $requestedScopes);
        }
        $url = "https://login.eveonline.com/oauth/authorize/?response_type=code&redirect_uri=https://zkillboard.com/ccpcallback/&client_id=$ccpClientID&scope=$scopes&state=$state";
        $app->redirect($url, 302);
        exit();
    }

    public static function callback()
    {
        global $mdb, $app, $redis, $ccpClientID, $ccpSecret, $adminCharacter;

        Status::throttle('sso');
        try {
            $charID = @$_SESSION['characterID'];
            $hash = @$_SESSION['characterHash'];

            if ($charID != null && $hash != null) {
                $value = $redis->get("login:$charID:$hash");
                if ($value == true) {
                    $app->redirect('/', 302);
                    exit();
                }
            }

            $state = str_replace("/", "", @$_GET['state']);
            $sessionState = @$_SESSION['oauth2State'];
            if ($state !== $sessionState) {
                $app->render("error.html", ['message' => "Something went wrong with the login from CCP's end, sorry, can you please try logging in again?"]);
                exit();
            }

            $url = 'https://login.eveonline.com/oauth/token';
            $verify_url = 'https://login.eveonline.com/oauth/verify';
            $header = 'Authorization: Basic '.base64_encode($ccpClientID.':'.$ccpSecret);
            $fields_string = '';
            $fields = array(
                    'grant_type' => 'authorization_code',
                    'code' => $_GET['code'],
                    );
            foreach ($fields as $key => $value) {
                $fields_string .= $key.'='.$value.'&';
            }
            rtrim($fields_string, '&');
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($header));
            curl_setopt($ch, CURLOPT_POST, count($fields));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $result = curl_exec($ch);

            if ($result === false) {
                auth_error(curl_error($ch));
            }
            curl_close($ch);
            $response = json_decode($result, true);

            if (isset($response['error'])) {
                $app->render("error.html", ['message' => "Something went wrong with the login from CCP's end, sorry, can you please try logging in again?"]);
                exit();
            }

            $access_token = $response['access_token'];
            $refresh_token = $response['refresh_token'];
            $ch = curl_init();
            // Get the Character details from SSO
            $header = 'Authorization: Bearer '.$access_token;
            curl_setopt($ch, CURLOPT_URL, $verify_url);
            curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($header));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $result = curl_exec($ch);
            if ($result === false) {
                auth_error(curl_error($ch));
            }
            curl_close($ch);
            $response = json_decode($result);
            if (!isset($response->CharacterID)) {
                auth_error('No character ID returned');
            }
            if (strpos(@$response->Scopes, 'publicData') === false) {
                auth_error('Expected at least publicData scope but did not get it.');
            }
            $charID = (int) $response->CharacterID;
            $charName = isset($response->CharacterName) ? (string) $response->CharacterName : $charID;
            $corpID = Info::getInfoField("characterID", $charID, "corporationID");
            $scopes = split(' ', (string) @$response->Scopes);

            // Clear out existing scopes
            if ($charID != $adminCharacter) $mdb->remove("scopes", ['characterID' => $charID]);

            foreach ($scopes as $scope) {
                if ($scope == "publicData") continue;
                $row = ['characterID' => $charID, 'scope' => $scope, 'refreshToken' => $refresh_token];
                if ($mdb->count("scopes", ['characterID' => $charID, 'scope' => $scope]) == 0) {
                    try {
                        $mdb->save("scopes", $row);
                    } catch (Exception $ex) {}
                } 
                switch ($scope) {
                    case 'esi-killmails.read_killmails.v1':
                        $esi = new RedisTimeQueue('tqApiESI', 3600);
                        // // Do this first, prevents race condition if charID already exists
                        // If a user logs in, check their api for killmails right away
                        $esi->setTime($charID, 0);

                        // If we didn't already have their api, this will add it and it will be
                        // checked right away as well
                        $esi->add($charID);
                        break;
                    case 'esi-killmails.read_corporation_killmails.v1':
                        $esi = new RedisTimeQueue('tqCorpApiESI', 3600);
                        if ($corpID > 1999999) $esi->add($corpID);
                        break;
                }
            }

            // Ensure we have admin character scopes saved, if not, redirect to retrieve them
            if ($charID == $adminCharacter) {
                $neededScopes = ['esi-wallet.read_character_wallet.v1', 'esi-wallet.read_corporation_wallets.v1', 'esi-mail.send_mail.v1'];
                $doRedirect = false;
                foreach ($neededScopes as $neededScope) {
                    if ($mdb->count("scopes", ['characterID' => $charID, 'scope' => $neededScope]) == 0) $doRedirect = true;
                }
                if ($doRedirect) {
                    $factory = new \RandomLib\Factory;
                    $generator = $factory->getGenerator(new \SecurityLib\Strength(\SecurityLib\Strength::MEDIUM));
                    $state = $generator->generateString(128, "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
                    $_SESSION['oauth2State'] = $state;


                    $neededScopes[] = 'publicData';
                    $neededScopes = implode('+', $neededScopes);
                    $url = "https://login.eveonline.com/oauth/authorize/?response_type=code&redirect_uri=https://zkillboard.com/ccpcallback/&client_id=$ccpClientID&scope=$neededScopes&state=$state";

                    header("Location: $url", 302);
                    exit('');
                }
            }

            // Lookup the character details in the DB.
            $userdetails = $mdb->findDoc('information', ['type' => 'characterID', 'id' => $charID]);
            if (!isset($userdetails['name'])) {
                if ($userdetails == null) {
                    $mdb->save('information', ['type' => 'characterID', 'id' => $charID, 'name' => $response->CharacterName]);
                }
            } else $mdb->removeField('information', ['type' => 'characterID', 'id' => $charID], 'lastApiUpdate'); // force an api update
            $rtq = new RedisTimeQueue("zkb:characterID", 86400);
            $rtq->add($charID, -1);

            ZLog::add("Logged in: $charName", $charID, true);
            unset($_SESSION['oauth2State']);

            $key = "login:$charID:" . session_id();
            $redis->setex("$key:refreshToken", (86400 * 14), $refresh_token);
            $redis->setex("$key:accessToken", 1000, $access_token);
            $redis->setex("$key:scopes", (86400 * 14), @$response->Scopes);

            $_SESSION['characterID'] = $charID;
            $_SESSION['characterName'] = $response->CharacterName;
            session_write_close();

            try {
                $mdb->insert("rewards", ['character_id' => $charID, 'character_name' => $charName]);
            } catch (Exception $rewardex) {}

            $redirect = '/';
            $sessID = session_id();
            $forward = $redis->get("forward:$sessID");
            $redis->del("forward:$sessID");
            $loginPage = UserConfig::get('loginPage', 'character');
            if ($loginPage == 'previous' && $forward !== null) {
                $redirect = $forward;
            } else {
                $corpID = Info::getInfoField("characterID", $charID, "corporationID");
                $alliID = Info::getInfoField("characterID", $charID, "allianceID");
                if (@$_SESSION['patreon'] == true) $redirect = '/cache/bypass/login/patreon/';
                elseif ($loginPage == "main") $redirect = "/";
                elseif ($loginPage == 'character') $redirect = "/character/$charID/";
                elseif ($loginPage == 'corporation' && $corpID > 0) $redirect = "/corporation/$corpID/";
                elseif ($loginPage == 'alliance' && $alliID > 0) $redirect = "/alliance/$alliID/";
                else $redirect = "/";
            }
            header('Location: '.$redirect, 302);
            Status::addStatus('sso', true);
            exit();
        } catch (Exception $ex) {
            $app->render("error.html", ['message' => "An unexpected error has happened, it has been logged and will be checked into. Please try to log in again."]);
            Log::log(print_r($ex, true));
            Status::addStatus('sso', false);
            exit();
        }
    }

    public static function getAccessToken($charID = null, $sessionID = null, $refreshToken = null)
    {
        global $app, $redis, $ccpClientID, $ccpSecret;

        Status::check('sso', true, false);


        if ($charID === null) {
            $charID = User::getUserID();
        }
        if ($sessionID === null) {
            $sessionID = session_id();
        }
        if ($refreshToken === null) {
            $refreshToken = $redis->get("login:$charID:$sessionID:refreshToken");
        }

        $key = "login:$charID:$sessionID:$refreshToken";
        $accessToken = $redis->get("$key:accessToken");

        if ($accessToken != null) {
            return $accessToken;
        }

        if ($refreshToken == null) {
            $refreshToken = $redis->get("$key:refreshToken");
        }
        if ($charID  == null || $refreshToken == null) {
            Util::out("No refreshToken for $charID with key $key");

            return $app !== null ? $app->redirect('/ccplogin/', 302) : null;
        }
        $redis->setex("$key:refreshToken", (86400 * 14), $refreshToken); // Reset the timer on the refreshToken
        $fields = array('grant_type' => 'refresh_token', 'refresh_token' => $refreshToken);

        $url = 'https://login.eveonline.com/oauth/token';
        $header = 'Authorization: Basic '.base64_encode($ccpClientID.':'.$ccpSecret);
        $fields_string = '';
        foreach ($fields as $arrKey => $value) {
            $fields_string .= $arrKey.'='.$value.'&';
        }
        $fields_string = rtrim($fields_string, '&');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array($header));
        curl_setopt($ch, CURLOPT_POST, count($fields));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result = json_decode($raw, true);
        $accessToken = @$result['access_token'];
        if ($accessToken != null) {
            $redis->setex("$key:accessToken", 1000, $accessToken);
        } else {
            if (isset($result['error'])) {
                return $result;
            }

            Status::addStatus('sso', false);
            return $httpCode;
        }

        Status::addStatus('sso', true);
        return $accessToken;
    }

    public static function crestGet($url, $accessToken = null)
    {
        $accessToken = $accessToken == null ? self::getAccessToken() : $accessToken;
        $authHeader = "Authorization: Bearer $accessToken";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$url?access_token=$accessToken");
        curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array($authHeader));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $result = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($result, true);

        return $json;
    }

    public static function crestPost($url, $fields, $accessToken = null)
    {
        $accessToken = $accessToken == null ? self::getAccessToken() : $accessToken;
        $authHeader = "Authorization: Bearer $accessToken";
        $data = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$url");
        curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array($authHeader, 'Content-Type:application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($result, true);
        $json['httpCode'] = $httpCode;

        return $json;
    }

    public static function getAccessTokenCallback(&$guzzler, $refreshToken, $success, $fail, &$params)
    {
        global $ccpClientID, $ccpSecret;

        Status::throttle('sso');
        $headers = ['Authorization' =>'Basic ' . base64_encode($ccpClientID . ':' . $ccpSecret), "Content-Type" => "application/json"];
        $url = 'https://login.eveonline.com/oauth/token';
        $guzzler->call($url, $success, $fail, $params, $headers, 'POST', json_encode(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]));
    }
}
