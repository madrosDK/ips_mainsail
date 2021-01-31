<?php

require_once __DIR__ . '/../libs/Ping_ALT.php';

class Mainsail extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("Scheme", "http");
        $this->RegisterPropertyString("Host", "");
        $this->RegisterPropertyString("APIKey", "");
        $this->RegisterPropertyInteger("UpdateInterval", 1);
        $this->RegisterPropertyBoolean("CamEnabled", false);
        $this->RegisterPropertyBoolean("EnclosureNeopixel", false);

        $this->RegisterTimer("Update", $this->ReadPropertyInteger("UpdateInterval"), 'OCTO_UpdateData($_IPS[\'TARGET\']);');
        $this->RegisterScript("NeopixelsOn", "Neopixels On", "<?php\n\nOCTO_LightsOn(" . $this->InstanceID . ");", 0);
        $this->RegisterScript("NeopixelsOff", "Neopixels Off", "<?php\n\nOCTO_LightsOff(" . $this->InstanceID . ");", 0);

        $this->CreateVarProfile("MAINSAIL.Size", 2, " MB", 0, 9999, 0, 1, "Database");
        $this->CreateVarProfile("MAINSAIL.Completion", 2, " %", 0, 100, 0.01, 2, "Hourglass");
        $this->CreateVarProfile("MAINSAIL.Length", 2, " mm", 0, 500, 0.1, 1, "Distance");
        $this->CreateThumbnail();  //thumbnail


    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        if ($this->ReadPropertyString("Host") != "") {
            $this->SetTimerInterval("Update", $this->ReadPropertyInteger("UpdateInterval") * 1000 * 60);

            if ($this->ReadPropertyBoolean("CamEnabled")) {
                $url = $this->ReadPropertyString("Scheme") . '://' . $this->ReadPropertyString("Host");
                $streamUrl = $url . ':8080/?action=stream';
                $media = @IPS_GetMediaIDByName("Cam Stream", $this->InstanceID);
                if (!$media) {
                    $media = IPS_CreateMedia(3);
                    IPS_SetIdent($media, "CamStream");
                    IPS_SetName($media, "Cam Stream");
                    IPS_SetMediaFile($media, $streamUrl, true);
                    IPS_SetParent($media, $this->InstanceID);
                } else {
                    if (md5(IPS_GetMedia($media)['MediaFile']) != md5($streamUrl)) {
                        IPS_SetMediaFile($media, $streamUrl, true);
                    }
                }
            }

            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }

//MaintainVariable(string $Ident, string $Name, integer $Typ, string $Profil, integer $Position, boolean $Beibehalten)
//Typ 0: Boolean, 1: Integer, 2: Float, 3: String; $Position = Position im Baum



        $this->MaintainVariable("Status", "Status", 3, "TextBox", 0, true);

        $this->MaintainVariable("BedTempActual", "Bed Temperature Actual", 2, "Temperature", 0, true);
        $this->MaintainVariable("BedTempTarget", "Bed Temperature Target", 2, "Temperature", 0, true);
        $this->MaintainVariable("ToolTempActual", "Nozzle Temperature Actual", 2, "Temperature", 0, true);
        $this->MaintainVariable("ToolTempTarget", "Nozzle Temperature Target", 2, "Temperature", 0, true);
        $this->MaintainVariable("Height", "Z Height", 2, "MAINSAIL.Length", 0, true);
        $this->MaintainVariable("ObjectHeight", "Object Height", 2, "MAINSAIL.Length", 0, true);


        //$this->MaintainVariable("FileSize", "File Size", 2, "MAINSAIL.Size", 0, true);
        $this->MaintainVariable("FileName", "File Name", 3, "TextBox", 0, true);
        $this->MaintainVariable("TotalTime", "Total Time", 3, "TextBox", 0, true);
        $this->MaintainVariable("PrintTime", "Print Time", 3, "TextBox", 0, true);
        $this->MaintainVariable("PrintTimeLeft", "Print Time Left", 3, "TextBox", 0, true);
        $this->MaintainVariable("ProgressCompletion", "Progress Completion", 2, "MAINSAIL.Completion", 2, true);
        $this->MaintainVariable("SlicerETA", "ETA Slicer", 3, "TextBox", 0, true);
        $this->MaintainVariable("FilemantETA", "ETA Filament", 3, "TextBox", 0, true);
        $this->MaintainVariable("Filament", "Filament total", 2, "MAINSAIL.Length", 0, true);
        $this->MaintainVariable("FilamentUsed", "Filament used", 2, "MAINSAIL.Length", 0, true);
        $this->MaintainVariable("Test", "Test", 3, "TextBox", 0, true);


    }

    public function UpdateData() {
        $ping = new Ping($this->ReadPropertyString("Host"));
        if ($ping->ping() == false) {
            $this->SendDebug(__FUNCTION__, 'Mainsail is offline', 0);
            return;
        } 

        $data = $this->RequestAPI('/printer/info');

        $data = $this->RequestAPI('/printer/objects/query?heater_bed');
        SetValue($this->GetIDForIdent("BedTempActual"), $this->FixupInvalidValue($data->result->status->heater_bed->temperature));
        SetValue($this->GetIDForIdent("BedTempTarget"), $this->FixupInvalidValue($data->result->status->heater_bed->target));

        $data = $this->RequestAPI('/printer/objects/query?extruder');
        SetValue($this->GetIDForIdent("ToolTempActual"), $this->FixupInvalidValue($data->result->status->extruder->temperature));
        SetValue($this->GetIDForIdent("ToolTempTarget"), $this->FixupInvalidValue($data->result->status->extruder->target));

        $data = $this->RequestAPI('/printer/objects/query?gcode_move');
        SetValue($this->GetIDForIdent("Height"), $this->FixupInvalidValue($data->result->status->gcode_move->position[2]));

        $data = $this->RequestAPI('/printer/objects/query?print_stats');
        //SetValue($this->GetIDForIdent("FileSize"), $this->FixupInvalidValue($data->job->file->size) / 1000000);
        SetValue($this->GetIDForIdent("Status"), $data->result->status->print_stats->state);
        SetValue($this->GetIDForIdent("FilamentUsed"), $data->result->status->print_stats->filament_used);
        SetValue($this->GetIDForIdent("FileName"), $data->result->status->print_stats->filename);
        SetValue($this->GetIDForIdent("PrintTime"), $this->CreateDuration($data->result->status->print_stats->print_duration));


        $data = $this->RequestAPI('/printer/objects/query?virtual_sdcard');
        SetValue($this->GetIDForIdent("ProgressCompletion"), $this->FixupInvalidValue($data->result->status->virtual_sdcard->progress*100));

        $data = $this->RequestAPI('/server/files/metadata?filename='.GetValue($this->GetIDForIdent("FileName")));
        SetValue($this->GetIDForIdent("Filament"), $this->FixupInvalidValue($data->result->filament_total));
        SetValue($this->GetIDForIdent("TotalTime"), $this->CreateDuration($data->result->estimated_time));
        SetValue($this->GetIDForIdent("ObjectHeight"), $this->FixupInvalidValue($data->result->object_height-0.4));
        IPS_SetMediaContent($this->GetIDForIdent("thumbnail"), $data->result->thumbnails[1]->data);

        SetValue($this->GetIDForIdent("PrintTimeLeft"), $this->CreateDuration($this->CreateUnix(GetValue($this->GetIDForIdent("TotalTime")))-$this->CreateUnix(GetValue($this->GetIDForIdent("PrintTime")))));
        SetValue($this->GetIDForIdent("SlicerETA"), $this->CreatePrintFinished($this->CreateUnix(GetValue($this->GetIDForIdent("TotalTime")))-$this->CreateUnix(GetValue($this->GetIDForIdent("PrintTime")))));
        SetValue($this->GetIDForIdent("FilemantETA"), $this->CreatePrintFinished($this->FixupInvalidValue($this->FilamentETA())));

        //Test zum auslesen über ID
//        SetValue($this->GetIDForIdent("Test"), $this->CreateDuration($this->CreateUnix(GetValue($this->GetIDForIdent("TotalTime")))-$this->CreateUnix(GetValue($this->GetIDForIdent("PrintTime")))));
//        SetValue($this->GetIDForIdent("PrintFinished"), $this->CreatePrintFinished($data->result->status->print_stats->print_duration));
    }

    public function LightsOff() {
        if ($this->ReadPropertyBoolean("EnclosureNeopixel")) {
            $url = $this->ReadPropertyString("Scheme") . '://' . $this->ReadPropertyString("Host");
            $this->httpGet($url . "/plugin/enclosure/setNeopixel?index_id=1&red=0&green=0&blue=0");
        }
    }

    public function LightsOn() {
        if ($this->ReadPropertyBoolean("EnclosureNeopixel")) {
            $url = $this->ReadPropertyString("Scheme") . '://' . $this->ReadPropertyString("Host");
            $this->httpGet($url . "/plugin/enclosure/setNeopixel?index_id=1&red=255&green=255&blue=255");
        }
    }

    private function RequestAPI($path) {
        $url = $this->ReadPropertyString("Scheme") . '://' . $this->ReadPropertyString("Host");
        $apiKey = $this->ReadPropertyString("APIKey");

        $this->SendDebug("Mainsail Requested URL", $url, 0);
        $headers = array(
            'X-Api-Key: ' . $apiKey
        );
        $ch = curl_init($url . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);

        $content = json_decode($response);
        $this->SendDebug("Mainsail Response", print_r($content, true), 0);
        if (isset($content->response->error)) {
            throw new Exception("Response from Mainsail is invalid: " . $content->response->error->description);
        }
        return $content;
    }

    private function FixupInvalidValue($Value) {
        if (is_numeric($Value)) {
            return floatval($Value);
        } else {
            return 0;
        }
    }

    private function CreateThumbnail() {
    $media = $this->GetIDForIdent("thumbnail");
    if (!$media)
      {
        $media = IPS_CreateMedia(1);
        IPS_SetIdent($media, "thumbnail");
        IPS_SetName($media, "thumbnail");
        IPS_SetMediaContent($media, "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");
        IPS_SetParent($media, $this->InstanceID);
      }
    else
      {
        return 0;
      }

  }
    private function CreateVarProfile($name, $ProfileType, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits, $Icon) {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $ProfileType);                       //0 Boolean; 1 Integer; 2 Float; 3 String
            IPS_SetVariableProfileText($name, "", $Suffix);
            IPS_SetVariableProfileValues($name, $MinValue, $MaxValue, $StepSize); //min, max, Schrittweite
            IPS_SetVariableProfileDigits($name, $Digits);                         //Anzahl Nachkommastellen
            IPS_SetVariableProfileIcon($name, $Icon);                             //https://www.symcon.de/service/dokumentation/komponenten/icons/
        }
    }

    private function CreateDuration($Value) {
        return gmdate("H:i:s", $this->FixupInvalidValue($Value));
    }

    private function FilamentETA() {
        $filament_used=GetValue($this->GetIDForIdent("FilamentUsed"));
        $filament_total=GetValue($this->GetIDForIdent("Filament"));
        $printtime=$this->CreateUnix(GetValue($this->GetIDForIdent("PrintTime")));
        if ($filament_used > 0 && $filament_total > $filament_used)
          {
            return ($printtime / ($filament_used/$filament_total)-$printtime);
          }
        else
          {
            return 0;
          }
    }

    private function CreateUnix($Value) {
        $hms = explode(":", $Value);
        return ($hms[0]*3600 + ($hms[1]*60) + ($hms[2]));
    }

    private function CreatePrintFinished($Value) {
        if (is_numeric($Value)) {
            $timestamp = time();
            $time = $timestamp + $Value;
            return date('l G:i', $time) . ' Uhr';
        } else {
            return "Calculating ...";
        }
    }

    private function httpGet($url) {
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_TIMEOUT, 1000);
        $data = curl_exec($handle);
        curl_close($handle);
    }
}
