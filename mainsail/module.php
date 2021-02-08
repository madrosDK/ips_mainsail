<?php

require_once __DIR__ . '/../libs/Ping.php';

class Mainsail extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("Scheme", "http");
        $this->RegisterPropertyString("Host", "");
        $this->RegisterPropertyString("APIKey", "");
        $this->RegisterPropertyString("TelegramID", "");
        $this->RegisterPropertyString("Recipient", "");
        $this->RegisterPropertyInteger("UpdateInterval", 1);
        $this->RegisterPropertyBoolean("CamEnabled", false);
        $this->RegisterPropertyBoolean("EnclosureNeopixel", false);

        $this->RegisterTimer("Update", $this->ReadPropertyInteger("UpdateInterval"), 'MAINSAIL_UpdateData($_IPS[\'TARGET\']);');
        $this->RegisterScript("NeopixelsOn", "Neopixels On", "<?php\n\nMAINSAIL_LightsOn(" . $this->InstanceID . ");", 0);
        $this->RegisterScript("NeopixelsOff", "Neopixels Off", "<?php\n\nMAINSAIL_LightsOff(" . $this->InstanceID . ");", 0);

        $this->CreateVarProfile("MAINSAIL.Size", 2, " MB", 0, 9999, 0, 1, "Database");
        $this->CreateVarProfile("MAINSAIL.Completion", 2, " %", 0, 100, 0.01, 2, "Hourglass");
        $this->CreateVarProfile("MAINSAIL.Length", 2, " mm", 0, 500, 0.1, 1, "Distance");

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



        $this->MaintainVariable("Status", "Status", 3, "", 0, true);

        $this->MaintainVariable("BedTempActual", "Bed Temperature Actual", 2, "Temperature", 0, true);
        $this->MaintainVariable("BedTempTarget", "Bed Temperature Target", 2, "Temperature", 0, true);
        $this->MaintainVariable("ToolTempActual", "Nozzle Temperature Actual", 2, "Temperature", 0, true);
        $this->MaintainVariable("ToolTempTarget", "Nozzle Temperature Target", 2, "Temperature", 0, true);
        $this->MaintainVariable("Height", "Z Height", 2, "MAINSAIL.Length", 0, true);
        $this->MaintainVariable("ObjectHeight", "Object Height", 2, "MAINSAIL.Length", 0, true);

        $this->MaintainVariable("Message", "Message", 0, "", 0, true);
        $this->MaintainVariable("FileName", "File Name", 3, "", 0, true);
        $this->MaintainVariable("TotalTime", "Total Time", 3, "", 0, true);
        $this->MaintainVariable("PrintTime", "Print Time", 3, "", 0, true);
        $this->MaintainVariable("PrintTimeLeft", "Print Time Left", 3, "", 0, true);
        $this->MaintainVariable("ProgressCompletion", "Progress Completion", 2, "MAINSAIL.Completion", 2, true);
        $this->MaintainVariable("SlicerETA", "ETA Slicer", 3, "", 0, true);
        $this->MaintainVariable("FilemantETA", "ETA Filament", 3, "", 0, true);
        $this->MaintainVariable("Filament", "Filament total", 2, "MAINSAIL.Length", 0, true);
        $this->MaintainVariable("FilamentUsed", "Filament used", 2, "MAINSAIL.Length", 0, true);
        $this->MaintainVariable("Test", "Test", 3, "", 0, true);
        $this->CreateThumbnail();  //thumbnail


    }

    public function UpdateData() {
        $ping = Ping($this->ReadPropertyString("Host"));
        if ($ping != false) {
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
        SetValue($this->GetIDForIdent("Status"), $data->result->status->print_stats->state);
        SetValue($this->GetIDForIdent("FilamentUsed"), $data->result->status->print_stats->filament_used);
        SetValue($this->GetIDForIdent("FileName"), $data->result->status->print_stats->filename);
        SetValue($this->GetIDForIdent("PrintTime"), $this->CreateDuration($data->result->status->print_stats->print_duration));


        $data = $this->RequestAPI('/printer/objects/query?virtual_sdcard');
        SetValue($this->GetIDForIdent("ProgressCompletion"), $this->FixupInvalidValue($data->result->status->virtual_sdcard->progress*100));

        $data = $this->RequestAPI('/server/files/metadata?filename='.str_replace('+','%2B',GetValue($this->GetIDForIdent("FileName"))));
        SetValue($this->GetIDForIdent("Filament"), $this->FixupInvalidValue($data->result->filament_total));
        SetValue($this->GetIDForIdent("TotalTime"), $this->CreateDuration($data->result->estimated_time));
        SetValue($this->GetIDForIdent("ObjectHeight"), $this->FixupInvalidValue($data->result->object_height-0.4));
        IPS_SetMediaContent($this->GetIDForIdent("thumbnail"), $data->result->thumbnails[1]->data);

        SetValue($this->GetIDForIdent("PrintTimeLeft"), $this->CreateDuration($this->CreateUnix(GetValue($this->GetIDForIdent("TotalTime")))-$this->CreateUnix(GetValue($this->GetIDForIdent("PrintTime")))));
        SetValue($this->GetIDForIdent("SlicerETA"), $this->CreatePrintFinished($this->CreateUnix(GetValue($this->GetIDForIdent("TotalTime")))-$this->CreateUnix(GetValue($this->GetIDForIdent("PrintTime")))));
        SetValue($this->GetIDForIdent("FilemantETA"), $this->CreatePrintFinished($this->FixupInvalidValue($this->FilamentETA())));
        $this->Telegram($this->GetIDForIdent("Status"));
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
    //$media = @$this->GetIDForIdent("thumbnail");
    $media=@IPS_GetObjectIDByName("thumbnail", $this->InstanceID);
    //$ObjektID =@IPS_GetObjectIDByName("Regenerfassung", $ParentID)
    if (!$media) //@ unterdrückt Meldung
  //  if (!$media)
      {
        $media = IPS_CreateMedia(1);
        $ImageFile = __DIR__.'/media/na.jpg';
        IPS_SetParent($media, $this->InstanceID);
        IPS_SetIdent($media, "thumbnail");
        IPS_SetName($media, "thumbnail");
        IPS_SetMediaFile($media, $ImageFile, true);
        //IPS_SetMediaContent($media, "R0lGODdhEAAQAMwAAPj7+FmhUYjNfGuxYYDJdYTIeanOpT+DOTuANXi/bGOrWj6CONzv2sPjv2CmV1unU4zPgISg6DJnJ3ImTh8Mtbs00aNP1CZSGy0YqLEn47RgXW8amasW7XWsmmvX2iuXiwAAAAAEAAQAAAFVyAgjmRpnihqGCkpDQPbGkNUOFk6DZqgHCNGg2T4QAQBoIiRSAwBE4VA4FACKgkB5NGReASFZEmxsQ0whPDi9BiACYQAInXhwOUtgCUQoORFCGt/g4QAIQA7");
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

    private function Telegram($Value) {
        $id = $this->ReadPropertyString("TelegramID");
        $message = GetValue($this->GetIDForIdent("Message"));
        $printtime = GetValue($this->GetIDForIdent("PrintTime"));
        $recipient = $this->ReadPropertyString("Recipient");
        //require_once('' . $id . '.ips.php');
        include(IPS_GetScriptFile($id));
        if ($Value == "printing" && $message == true)
        {
          $text="Drucker " . IPS_GetName(IPS_GetParent($printtime)) . "ist nach " . $printtime . " fertig";
          Telegram_SendText($InstanzID, $text, $recipient, $ParseMode='Markdown');
          SetValue($message,false);
        }
        else
        {
          if ($Value == "error" && $message == true)
            {
              $text="Drucker " . IPS_GetName(IPS_GetParent($printtime)) . "hat einen Fehler gemeldet";
              Telegram_SendText($InstanzID, $text, $recipient, $ParseMode='Markdown');
              SetValue($message,false);
            }
          else
          {

          }
        }
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
