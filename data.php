<?php
	$error = null;

	require_once('mariadb.php');

	if(!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] != true)
	{
		echo json_encode(array('status' => 403, 'error' => "You don't have access to this webpage"));
		exit;
	}

	function getWho($reason)
	{
		if($reason == "ExternalIrCommand")
			$who = "Remote";
		else if($reason == "UserRequest")
			$who = "App";
		else if($reason == "UserAPI")
			$who = "API";
		else
			$who = "Unknown";

		return $who;
	}

	if(isset($_REQUEST['startTS']) && $_REQUEST['startTS'] != -1)
		$startTS = doubleval($_REQUEST['startTS']);
	else
		$startTS = time() * 1000 - 86400000;

	if($error != null)
		reportError($error);

	$dataPoints1 = array();
	$dataPoints2 = array();
	$dataPoints3 = array();
	$dataPoints4 = array();

	$airconon = '';
	$uid = '';
	$ac = 'off';
	$currhumid = 0;
	$currtemp = 0.0;
	$currtime = "00:00";

	if(isset($_REQUEST['uid']) && $_REQUEST['uid'] != '')
		$uid = mysqli_real_escape_string($link, $_REQUEST['uid']);

	$query = "SELECT uid FROM devices";
	if($uid != '')
		$query .= " WHERE uid='$uid'";
	$query .= " LIMIT 1";
	$res = mysqli_query($link, $query);
	$uid = mysqli_fetch_assoc($res)['uid'];

	if(!isset($uid) || $uid == '')
	{
		$error = "Unable to get a UID, please check your database/configs and try again";
		reportError($error);
	}

	$query = "SELECT UNIX_TIMESTAMP(whentime) * 1000 as whentime,DATE_FORMAT(whentime, '%H:%i') as wttime,".
			"temperature,humidity,feelslike,rssi,airconon FROM sensibo ".
			"WHERE uid='$uid' AND UNIX_TIMESTAMP(whentime) * 1000 >= $startTS AND ".
			"UNIX_TIMESTAMP(whentime) * 1000 <= $startTS + 86400000 ORDER BY whentime ASC";
	$res = mysqli_query($link, $query);
	while($row = mysqli_fetch_assoc($res))
	{
		if($row['airconon'] != $airconon && $airconon != '')
		{
			$airconon = $row['airconon'];

			$ac = "off";
			if($airconon == 1)
				$ac = "on";

			if($ac == "on")
				$dataPoints1[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['temperature']), 'inindexLabel' => $ac, 'markerType' => 'cross',  'markerSize' =>  20,'markerColor' => 'green');
			else
				$dataPoints1[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['temperature']), 'inindexLabel' => $ac, 'markerType' => 'cross',  'markerSize' =>  20,'markerColor' => 'tomato');
		} else {
			$dataPoints1[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['temperature']));
			$airconon = $row['airconon'];
		}

		$dataPoints2[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['humidity']));
		$dataPoints3[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['feelslike']));
		$dataPoints4[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['rssi']));

		$currtemp = $row['temperature'];
		$currhumid = $row['humidity'];
		$currtime = $row['wttime'];

		if($startTS == -1)
			$startTS = $row['whentime'];
	}

	$line1 = "<b>".$currtime."</b> -- ".$currtemp."°C, ".$currhumid."%";

	$lastdate = '';
	$commands = '';

	if(isset($_SESSION['rw']) && $_SESSION['rw'] == true)
	{
		$commands .= "<li style='text-align:center'>";
		$commands .= "<img style='width:32px;' onClick='temp(); return false;' src='temperature.png' />\n";
		$commands .= "<img style='width:32px;' onClick='fan(); return false;' src='fan.png' />\n";

		if($ac == "on")
			$commands .= "<img id='onoff' style='width:32px;' onClick='toggleAC(); return false;' src='off.png' />\n";
		else
			$commands .= "<img id='onoff' style='width:32px;' onClick='toggleAC(); return false;' src='on.png' />\n";

		$commands .= "<img style='width:32px;' onClick='logout(); return false;' src='exit.png' />\n";

		$commands .= "</li>\n";
		$commands .= "<li>&nbsp;</li>\n";
	}

	$commands .= "<li style='text-align:center;'><u><b>Current Conditions</b></u></li>\n";
	$commands .= "<li>$line1</li>\n";

	$query = "SELECT uid,name FROM devices ORDER BY name";
	$res = mysqli_query($link, $query);
	if(mysqli_num_rows($res) > 1)
	{
		$commands .= "<li><label for='devices'>Choose a Device:</label>\n";
		$commands .= "<select name='devices' id='devices' onChange='changeAC(this.value); return false;'>\n";

		while($row = mysqli_fetch_assoc($res))
		{
			$commands .= "<option value='".$row['uid']."'";
			if($uid == $row['uid'])
				$commands .= " selected";
			$commands .= ">".$row['name']."</option>\n";
		}
		$commands .= "</select></li>\n";
	}

	$query = "SELECT *, DATE_FORMAT(whentime, '%a %d %b %Y') as wtdate, DATE_FORMAT(whentime, '%H:%i') as wttime FROM commands WHERE uid='$uid' ORDER BY whentime DESC";
	$res = mysqli_query($link, $query);
	while($row = mysqli_fetch_assoc($res))
	{
		$onoff = true;
		$query = "SELECT * FROM commands WHERE uid='$uid' AND whentime < '".$row['whentime']."' ORDER BY whentime DESC LIMIT 1";
		$dres = mysqli_query($link, $query);
		$drow = mysqli_fetch_assoc($dres);

		$date = $row["wtdate"];
		if($date != $lastdate)
		{
			$commands .= "<li>&nbsp;</li>\n";
			$commands .= "<li style='text-align:center;'><u><b>$date</b></u></li>\n";
			$lastdate = $date;
		}

		$who = getWho($row["reason"]);

		if(isset($drow["targetTemperature"]) && $row["targetTemperature"] != $drow["targetTemperature"])
		{
			$onoff = false;
			$commands .= "<li><b>".$row["wttime"]."</b> -- $who set temperature to ".$row["targetTemperature"]."</li>\n";
		}

		if(isset($drow["targetTemperature"]) && $row["mode"] != $drow["mode"])
		{
			$onoff = false;
			$commands .= "<li><b>".$row["wttime"]."</b> -- $who set mode to ".$row["mode"]."</li>\n";
		}

		if(isset($drow["targetTemperature"]) && $row["fanLevel"] != $drow["fanLevel"])
		{
			$onoff = false;
			$commands .= "<li><b>".$row["wttime"]."</b> -- $who set fan to ".$row["fanLevel"]."</li>\n";
		}

		if(isset($drow["targetTemperature"]) && $row["swing"] != $drow["swing"])
		{
			$onoff = false;
			$commands .= "<li><b>".$row["wttime"]."</b> -- $who set swing to ".$row["swing"]."</li>\n";
		}

		if($onoff)
		{
			if($row["airconon"])
				$commands .= "<li><b>".$row["wttime"]."</b> -- $who set AC on</li>\n";
			else
				$commands .= "<li><b>".$row["wttime"]."</b> -- $who set AC off</li>\n";
		}
	}

	$data = array('uid' => $uid, 'dataPoints1' => $dataPoints1, 'dataPoints2' => $dataPoints2, 'dataPoints3' => $dataPoints3, 'dataPoints4' => $dataPoints4, 'commands' => $commands, 'currtime' => $currtime, 'startTS' => $startTS);
	echo json_encode(array('status' => 200, 'content' => $data));
