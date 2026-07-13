<?php

declare(strict_types=1);

class GoogleSonosTTS extends IPSModule
{
    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Register Properties
        $this->RegisterPropertyString("ApiKey", "");
        $this->RegisterPropertyString("VoiceName", "de-DE-Wavenet-C");
        $this->RegisterPropertyString("SymconBaseURL", "http://192.168.1.100:3777");
        
        $this->RegisterPropertyFloat("SpeakingRate", 1.0);
        $this->RegisterPropertyFloat("Pitch", 0.0);
        $this->RegisterPropertyString("SonosInstances", "[]");
        $this->RegisterPropertyString("RoonInstances", "[]");

        // Register Timers
        $this->RegisterTimer("CleanupTimer", 0, 'GSTTS_CleanupCache($_IPS[\'TARGET\']);');
        $this->RegisterTimer("ResumeRoonTimer", 0, 'GSTTS_ResumeRoon($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterHook("/hook/GoogleSonosTTS_" . $this->InstanceID);

        // Set Timer Interval to 24 hours (86400000 ms) in ApplyChanges
        $this->SetTimerInterval("CleanupTimer", 86400000);
    }

    public function ClearCache(): void
    {
        $userDir = IPS_GetKernelDir() . "webfront" . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR;
        $moduleDir = $userDir . "GoogleSonosTTS";
        if (is_dir($moduleDir)) {
            $files = glob($moduleDir . DIRECTORY_SEPARATOR . "*.mp3");
            $count = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    $count++;
                }
            }
            echo "Cache geleert. " . $count . " Dateien gelöscht.";
        } else {
            echo "Cache-Verzeichnis existiert nicht.";
        }
    }

    public function CleanupCache(): void
    {
        $userDir = IPS_GetKernelDir() . "webfront" . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR;
        $moduleDir = $userDir . "GoogleSonosTTS";
        if (is_dir($moduleDir)) {
            $files = glob($moduleDir . DIRECTORY_SEPARATOR . "*.mp3");
            $now = time();
            foreach ($files as $file) {
                if (is_file($file)) {
                    // Delete files older than 30 days
                    if ($now - filemtime($file) >= 30 * 24 * 3600) {
                        unlink($file);
                    }
                }
            }
        }
    }

    public function ResumeRoon(): void
    {
        $this->SetTimerInterval('ResumeRoonTimer', 0);
        $resumeList = json_decode($this->GetBuffer('RoonResumeIDs'), true);
        if (is_array($resumeList)) {
            foreach ($resumeList as $roonID) {
                if (IPS_InstanceExists($roonID) && function_exists('ROON_SendCommand')) {
                    $this->SendDebug("GoogleTTS", "Setze Roon Instanz fort: " . $roonID, 0);
                    ROON_SendCommand($roonID, 'play');
                }
            }
        }
        $this->SetBuffer('RoonResumeIDs', '[]');
    }

    protected function RegisterHook(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");
        if (sizeof($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ["Hook" => $WebHook, "TargetID" => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    protected function ProcessHookData()
    {
        $uri = $_SERVER['REQUEST_URI'];
        $parts = explode('?', $uri); // Remove query string if any
        $path = $parts[0];
        $file = basename($path);

        if ($file === '' || strpos($file, '.mp3') === false) {
            http_response_code(400);
            echo "No valid file specified";
            return;
        }

        $userDir = IPS_GetKernelDir() . "webfront" . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR;
        $moduleDir = $userDir . "GoogleSonosTTS";
        $filePath = $moduleDir . DIRECTORY_SEPARATOR . $file;

        if (file_exists($filePath)) {
            header("Content-Type: audio/mpeg");
            header("Content-Length: " . filesize($filePath));
            header("Accept-Ranges: bytes");
            readfile($filePath);
        } else {
            http_response_code(404);
            echo "File not found";
        }
    }

    public function PlayMessage(string $Text)
    {
        $this->SendDebug("GoogleTTS", "Starte Sprachausgabe mit Text: " . $Text, 0);

        $apiKey = $this->ReadPropertyString("ApiKey");
        $voiceName = $this->ReadPropertyString("VoiceName");
        $baseURL = $this->ReadPropertyString("SymconBaseURL");
        
        $allSonosIDs = [];
        $sonosList = json_decode($this->ReadPropertyString("SonosInstances"), true);
        if (is_array($sonosList)) {
            foreach ($sonosList as $item) {
                $isActive = isset($item['Active']) ? (bool)$item['Active'] : true;
                if (!$isActive) continue;

                $id = (int)($item['InstanceID'] ?? 0);
                $vol = $item['Volume'] ?? "+0";
                if ($vol === "") {
                    $vol = "+0";
                }
                if ($id > 0 && !isset($allSonosIDs[$id])) {
                    $allSonosIDs[$id] = $vol;
                }
            }
        }
        
        $roonList = json_decode($this->ReadPropertyString("RoonInstances"), true);
        $roonResumeList = [];
        if (is_array($roonList)) {
            foreach ($roonList as $item) {
                $isActive = isset($item['Active']) ? (bool)$item['Active'] : true;
                if (!$isActive) continue;

                $roonID = (int)($item['InstanceID'] ?? 0);
                if ($roonID > 0 && IPS_InstanceExists($roonID)) {
                    // Check if Roon is playing right now
                    $stateID = false;
                    try {
                        $stateID = IPS_GetObjectIDByIdent('State', $roonID);
                    } catch (Exception $e) {}
                    
                    if ($stateID !== false && GetValue($stateID) == 2) { // 2 = Play
                        $roonResumeList[] = $roonID;
                    }

                    $this->SendDebug("GoogleTTS", "Pausiere Roon Instanz: " . $roonID, 0);
                    if (function_exists('ROON_SendCommand')) {
                        ROON_SendCommand($roonID, 'pause');
                    } else {
                        $this->SendDebug("GoogleTTS", "ROON_SendCommand nicht gefunden, kann Roon nicht pausieren.", 0);
                    }
                }
            }
        }

        // Wenn wir Roon pausiert haben, warten wir 1 Sekunde, bevor wir mit der Sprachausgabe starten
        if (count($roonResumeList) > 0) {
            IPS_Sleep(1000);
        }

        $speakingRate = $this->ReadPropertyFloat("SpeakingRate");
        $pitch = $this->ReadPropertyFloat("Pitch");

        if (empty($apiKey)) {
            $err = "Fehler: Google Cloud API Key ist nicht konfiguriert.";
            echo $err;
            IPS_LogMessage('SmartVillaKunterbunt', 'GoogleSonosTTS: ' . $err);
            return false;
        }

        if (count($allSonosIDs) === 0) {
            $err = "Fehler: Keine aktiven Sonos Ziel-Instanzen konfiguriert.";
            echo $err;
            IPS_LogMessage('SmartVillaKunterbunt', 'GoogleSonosTTS: ' . $err);
            return false;
        }

        // Determine language code from voice name (e.g. de-DE-Wavenet-C -> de-DE)
        $languageCode = substr($voiceName, 0, 5);

        // Define target directory and file name
        $userDir = IPS_GetKernelDir() . "webfront" . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR;
        $moduleDir = $userDir . "GoogleSonosTTS";
        
        if (!is_dir($moduleDir)) {
            if (!mkdir($moduleDir, 0777, true)) {
                $err = "Fehler: Konnte Verzeichnis nicht erstellen: " . $moduleDir;
                echo $err;
                IPS_LogMessage('SmartVillaKunterbunt', 'GoogleSonosTTS: ' . $err);
                return false;
            }
        }

        // Include volume, pitch and rate in the hash so different settings generate different files!
        $hashString = $Text . $voiceName . $speakingRate . $pitch;
        $fileName = "tts_" . md5($hashString) . ".mp3";
        $filePath = $moduleDir . DIRECTORY_SEPARATOR . $fileName;

        if (!file_exists($filePath)) {
            $this->SendDebug("GoogleTTS", "Datei nicht im Cache. Sende Request an Google API...", 0);

            // Check if user is using SSML (Speech Synthesis Markup Language)
            $isSSML = (strpos(trim($Text), '<speak>') === 0);
            $inputPayload = $isSSML ? ["ssml" => $Text] : ["text" => $Text];

            // API Endpoint
            $url = "https://texttospeech.googleapis.com/v1/text:synthesize?key=" . $apiKey;

            // Request Payload
            $data = [
                "input" => $inputPayload,
                "voice" => [
                    "languageCode" => $languageCode,
                    "name" => $voiceName
                ],
                "audioConfig" => [
                    "audioEncoding" => "MP3",
                    "speakingRate" => $speakingRate,
                    "pitch" => $pitch
                ]
            ];

            // cURL Request
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->SendDebug("GoogleTTS", "Google API HTTP Code: " . $httpCode, 0);

            if ($httpCode !== 200) {
                $err = "Fehler bei der Google TTS API Anfrage. HTTP Code: " . $httpCode . "\nResponse: " . $response;
                echo $err;
                IPS_LogMessage('SmartVillaKunterbunt', 'GoogleSonosTTS: ' . $err);
                return false;
            }

            $result = json_decode($response, true);
            if (!isset($result['audioContent'])) {
                $err = "Fehler: Keine Audio-Daten von Google empfangen.";
                echo $err;
                IPS_LogMessage('SmartVillaKunterbunt', 'GoogleSonosTTS: ' . $err);
                return false;
            }

            $audioContent = base64_decode($result['audioContent']);

            $this->SendDebug("GoogleTTS", "Speichere MP3 in Pfad: " . $filePath, 0);

            // Write file
            if (file_put_contents($filePath, $audioContent) === false) {
                $err = "Fehler: Konnte MP3-Datei nicht schreiben: " . $filePath;
                echo $err;
                IPS_LogMessage('SmartVillaKunterbunt', 'GoogleSonosTTS: ' . $err);
                return false;
            }

            // Set permissions so the webserver can read it
            chmod($filePath, 0777);
        } else {
            $this->SendDebug("GoogleTTS", "Audio existiert bereits im Cache. Überspringe Google API Anfrage.", 0);
        }

        // Set Timer to resume Roon if needed
        if (count($roonResumeList) > 0) {
            $this->SetBuffer('RoonResumeIDs', json_encode($roonResumeList));
            // Calculate approximate duration: 32kbps MP3 (roughly 4000 bytes/sec), add 1.0s overhead
            $durationMs = (int)(max(2, (filesize($filePath) / 4000) + 1.0) * 1000);
            $this->SetTimerInterval('ResumeRoonTimer', $durationMs);
            $this->SendDebug("GoogleTTS", "Starte ResumeRoonTimer in " . $durationMs . " ms", 0);
        }

        // Construct URL via Webhook
        $baseURL = rtrim($baseURL, "/");
        $fileURL = $baseURL . "/hook/GoogleSonosTTS_" . $this->InstanceID . "/" . $fileName;

        $this->SendDebug("GoogleTTS", "Generierte Webhook-URL für Sonos: " . $fileURL, 0);

        // Play on Sonos
        $filesArray = json_encode([$fileURL]);

        if (function_exists('SNS_PlayFiles')) {
            foreach ($allSonosIDs as $sonosID => $Volume) {
                if (IPS_InstanceExists($sonosID)) {
                    $this->SendDebug("GoogleTTS", "Starte asynchrone Wiedergabe auf Instanz " . $sonosID . " mit Lautstärke " . $Volume . "...", 0);
                    $scriptCode = "SNS_PlayFiles(" . $sonosID . ", '" . $filesArray . "', '" . $Volume . "');";
                    IPS_RunScriptText($scriptCode);
                } else {
                    $this->SendDebug("GoogleTTS", "Warnung: Sonos Instanz " . $sonosID . " existiert nicht mehr.", 0);
                }
            }
        } else {
            $err = "Warnung: Funktion SNS_PlayFiles existiert nicht. Bitte sicherstellen, dass das Sonos Modul korrekt installiert ist.";
            echo $err;
            IPS_LogMessage('SmartVillaKunterbunt', 'GoogleSonosTTS: ' . $err);
            return false;
        }

        IPS_LogMessage('SmartVillaKunterbunt', 'GoogleSonosTTS: Sprachausgabe erfolgreich gestartet: ' . $Text);
        return $fileURL;
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'GoogleSonosTTS: ' . $Message);
        return true;
    }
}

