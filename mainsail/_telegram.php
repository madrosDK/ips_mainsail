<?
include '45855.ips.php'; //telegram
$waschmaschine_nachricht_verschickt = 25213;
$waschmaschine_Benachrichtigung = 44175;
$waschmaschine_watt = 38999;
$waschmaschine_status = 20518;

if (($IPS_SENDER == "Variable") and (GetValueBoolean($waschmaschine_Benachrichtigung) == true))
	{
    	if (($IPS_VALUE <= "5") and (GetValueBoolean($waschmaschine_nachricht_verschickt) == false))
			{
			//SetValueBoolean($waschmaschine_status,true);
    		IPS_SetScriptTimer($IPS_SELF, 90);
    		}
		else
			{
    		IPS_SetScriptTimer($IPS_SELF, 0);
        	SetValueBoolean($waschmaschine_status,true);
			SetValueBoolean($waschmaschine_nachricht_verschickt,false);
    		}
	}



if ($IPS_SENDER == "TimerEvent") {
    SetValueBoolean($waschmaschine_status,false);
	SetValueBoolean($waschmaschine_nachricht_verschickt,true);
	SetValueBoolean($waschmaschine_Benachrichtigung,false);
    IPS_SetScriptTimer($IPS_SELF, 0);
	$text="Waschmaschine ist fertig";
	Telegram_SendText($InstanzID, $text, $tg_Silke, $ParseMode='Markdown');
}


?>
