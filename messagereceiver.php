<?php
	//Server-dependent variables
	include 'SecretData.php';
	
	//Receive SMS
	$sqlink = selectBattleshipsDB(connectSQL());
	recordAccess($sqlink);
	
	try
	{
		$message_type = $_POST["message_type"];
		if ($message_type == "incoming")
		{
			$message = $_POST["message"];
			$mobile_number = $_POST["mobile_number"];
			$shortcode = $_POST["shortcode"];
			$timestamp = $_POST["timestamp"];
			$request_id = $_POST["request_id"];
			$tokens = explode(".", $message);
			echo "Accepted";
		}
		else
		{
			throw new Exception('message_type invalid');
		}
	}
	catch (Exception $e)
	{
		echo "Error";
		exit(0);
	}
	
	recordReceivedSMS($sqlink, $message_type, $message, $mobile_number, $shortcode, $timestamp, $request_id);
	
	//Queue System
	//IF CONNECT: Create Queue DB --> Queue Player (1 & 2) -WAIT-> Match Players --> Create Playing DB --> Dequeue Players --> Play Players --> Update Players
	if(strcmp($tokens[0], "CONNECT") == 0)
	{
		queuePlayer($sqlink, $mobile_number, $tokens[1]);
		matchPlayers($sqlink); //Contains SMS Send
	}
	elseif(strcomp($tokens[0], "ACTION") == 0)
	{
		actionPlayer($sqlink, findPlayerID($mobile_number), $token[1], $token[2]);
	}
	elseif(strcomp($tokens[0], "FORFEIT") == 0)
	{
		//endPlayers
	}
	else
	{
		echo "Command not recognized";
	}
	mysqli_close($sqlink); 
	
	function actionPlayer($link, $id, $oppblueprint, $turn) //untested
	{
		$sqlcommand = "SELECT turn, oppid FROM Playing WHERE id = $id";
		$selection = mysqli_query($link, $sqlcommand);
		if (mysqli_num_rows($selection) > 0) 
		{
			$selectedrow = mysqli_fetch_assoc($selection);
			$previousturn = $selectedrow["turn"];
			$oppid = $selectedrow["oppid"];
		}
		else
		{
			echo "ERROR OCCURED in actionPlayer! <br>";
		}
		$sqlcommand = "SELECT phone FROM Playing WHERE id = $oppid";
		$selection = mysqli_query($link, $sqlcommand);
		if (mysqli_num_rows($selection) > 0) 
		{
			$selectedrow = mysqli_fetch_assoc($selection);
			$oppphone = $selectedrow["phone"];
		}
		else
		{
			echo "ERROR OCCURED in actionPlayer! <br>";
		}
		
		//SMS opponent blueprint to opponent phone
		//insert opponent blueprint in Playing database
		if($turn > $previousturn)
		{
			$messagecontent = "UPDATE." . $oppblueprint . ".";
			replyText($oppphone, $messagecontent, getLastRequestId($oppphone));
			
			mysqli_query($link, "UPDATE Playing SET turn = $turn WHERE id = $id"); //put a conditional
			mysqli_query($link, "UPDATE Playing SET blueprint = '$oppblueprint' WHERE id = $oppid");
		}
		else
		{
			echo "ERROR: Turn is not greater than player's previous turn.";
		}
	}
	function findPlayerID($link, $phone)
	{
		$sqlcommand = "SELECT id FROM Playing WHERE phone = '$phone'";
		$selection = mysqli_query($link, $sqlcommand);
		if (mysqli_num_rows($selection) > 0) 
		{
			$selectedrow = mysqli_fetch_assoc($selection);
			return $selectedrow["id"];
		}
		else
		{
			echo "ERROR OCCURED in finding player ID! <br>";
			return -1;
		}
	}
	function connectSQL()
	{
		$link = mysqli_connect(SQLHOST,SQLUSER,SQLPASS); 
		if (!$link) 
		{ 
			die('Could not connect to MySQL: ' . mysqli_error()); 
		} 
		echo 'Connection OK <br>'; 
		return $link;
	}
	function createBattleshipsDB($link)
	{
		$sqlcommand = "CREATE DATABASE IF NOT EXISTS ".SQLDB;
		if (mysqli_query($link, $sqlcommand)) 
		{
			echo "Database created successfully <br>";
		} 
		else 
		{
			echo "Error creating database: " . mysqli_error($link);
		}
	}
	function selectBattleshipsDB($link)
	{
		createBattleshipsDB($link);
		$link = mysqli_connect(SQLHOST, SQLUSER, SQLPASS, SQLDB); 
			if (!$link) 
			{ 
				die('Could not connect to battleships: ' . mysqli_error()); 
			} 
		echo 'Database OK <br>'; 
		return $link;
	}
	function createQueue($link)
	{
		$sqlcommand = 	"CREATE TABLE IF NOT EXISTS Queue
						(
							id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
							phone VARCHAR(15) NOT NULL,
							blueprint VARCHAR(256) NOT NULL
						)";
		if (mysqli_query($link, $sqlcommand)) 
		{
			echo "Table Queue created successfully <br>";
		} 
		else 
		{
			echo "Error creating table: " . mysqli_error($link);
		}
	}
	function queuePlayer($link, $phonenumber, $blueprint)
	{
		createQueue($link);
		$sqlcommand = 	"INSERT INTO Queue(phone, blueprint) 
						VALUES('$phonenumber', '$blueprint')";
		if(mysqli_query($link, $sqlcommand))
		{
			echo "New record created successfully <br>";
		}
		else
		{
			echo "Error occured when creating record for Queue table";
		}
	}
	function matchPlayers($link)
	{
		$sqlcommand = "SELECT id, phone, blueprint FROM Queue";
		$selection = mysqli_query($link, $sqlcommand);
		if (mysqli_num_rows($selection) > 1) 
		{
			$playerid = array("", "");
			createPlaying($link);
			for($x = 0; $x < 2; $x++)
			{
				$selectedrow = mysqli_fetch_assoc($selection);
				$playerid[$x] = $selectedrow["id"];
				$playerphone[$x] = $selectedrow["phone"];
				$playerblueprint[$x] = $selectedrow["blueprint"];
				dequeuePlayer($link, $playerid[$x]);
			}
			for($x = 0; $x < 2; $x++)
			{
				playPlayer($link, $playerid[$x], $playerphone[$x], $playerblueprint[$x], $playerid[abs($x - 1)], ($x - 1));
			}
			updatePlayer($link, $playerid[0]);
			updatePlayer($link, $playerid[1]);
		} 
		else 
		{
			echo "No other players present. <br>";
		}
	}
	function dequeuePlayer($link, $id)
	{
		$sqlcommand = "DELETE FROM Queue WHERE id = $id";
		if(mysqli_query($link, $sqlcommand))
		{
			echo "Player $id successfully dequeued. <br>";
		}
		else
		{
			echo "Error occured when dequeuing player $id";
		}
	}
	function createPlaying($link)
	{
		$sqlcommand = 	"CREATE TABLE IF NOT EXISTS Playing
						(
							id INT(6) UNSIGNED PRIMARY KEY,
							phone VARCHAR(15) NOT NULL,
							blueprint VARCHAR(256) NOT NULL,
							turn INT(3),
							oppid INT(6) UNSIGNED
						)";
		if (mysqli_query($link, $sqlcommand)) 
		{
			echo "Table Playing created successfully <br>";
		} 
		else 
		{
			echo "Error creating table: " . mysqli_error($link);
		}
	}
	function playPlayer($link, $id, $phonenumber, $blueprint, $oppid, $turnstart)
	{
		$sqlcommand = 	"INSERT INTO Playing(id, phone, blueprint, oppid, turn) 
						VALUES($id, '$phonenumber', '$blueprint', $oppid, $turnstart)";
		if(mysqli_query($link, $sqlcommand))
		{
			echo "New record created successfully for playing <br>";
		}
		else
		{
			echo "Error occured when creating record for playing table";
		}
	}
	//SelfNote: Used for delivery notifications module. MessageID can be anything. It is used to prevent duplicates. That module needs to know if a message is a duplicate.
	function sendText($phone, $message)
	{
		$messageid = time() . $phone;

		if (!is_numeric($phone) && !mb_check_encoding($phone, 'UTF-8')) 
		{
			trigger_error('TO needs to be a valid UTF-8 encoded string');
			return false;
		}
		if (!mb_check_encoding($message, 'UTF-8')) 
		{
			trigger_error('Message needs to be a valid UTF-8 encoded string');
			return false;
		}
		$message = urlencode($message);

		$sendData = array(
			'mobile_number' => $phone,
			'message_id' => $messageid,
			'message' => $message,
			'message_type' => 'SEND'
			);
		return sendChikka($sendData);
	}
	function replyText($phone, $message, $requestid)
	{
		$messageid = time() . $phone;

		if (!is_numeric($phone) && !mb_check_encoding($phone, 'UTF-8')) 
		{
			trigger_error('TO needs to be a valid UTF-8 encoded string');
			return false;
		}
		if (!mb_check_encoding($message, 'UTF-8')) 
		{
			trigger_error('Message needs to be a valid UTF-8 encoded string');
			return false;
		}
		$message = urlencode($message);

		$sendData = array(
			'mobile_number' => $phone,
			'message_id' => $messageid,
			'message' => $message,
			'message_type' => 'REPLY',
			'request_id' => $requestid,
			'request_cost' => 'FREE'
			);
		return sendChikka($sendData);
	}
	function sendChikka($data)
	{
		echo "starting sendChikka <br>";
		$data = array_merge($data, 
			array
			(
			'client_id'=> CLIENTID, 
			'secret_key' => SECRETKEY,
			'shortcode' => SHORTCODE
			)
		);
		
		$query_string = "";
		foreach($data as $piece => $piecedata)
		{
			$query_string .= '&'.$piece.'='.$piecedata;
		}
		
		$ch = curl_init(CHIKKAURL);
		curl_setopt($ch, CURLOPT_POST, count($data));
		curl_setopt($ch, CURLOPT_POSTFIELDS, $query_string);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		curl_exec($ch);
		curl_close($ch);
		return true;
	}
	function updatePlayer($link, $id) //Update Player consists of opponent's blueprint and player's turn
	{
		$sqlcommand = "SELECT phone, turn, oppid FROM Playing WHERE id = $id";
		$selection = mysqli_query($link, $sqlcommand);
		if (mysqli_num_rows($selection) > 0) 
		{
			$selectedrow = mysqli_fetch_assoc($selection);
			$playerphone = $selectedrow["phone"];
			$playerturn = $selectedrow["turn"];
			$oppid = $selectedrow["oppid"];
		}
		else
		{
			echo "ERROR OCCURED in updatePlayer! <br>";
		}
		$sqlcommand = "SELECT blueprint FROM Playing WHERE id = $oppid";
		$selection = mysqli_query($link, $sqlcommand);
		if (mysqli_num_rows($selection) > 0) 
		{
			$selectedrow = mysqli_fetch_assoc($selection);
			$oppblueprint = $selectedrow["blueprint"];
		}
		else
		{
			echo "ERROR OCCURED in updatePlayer! <br>";
		}
		$textcontent = "UPDATE." . $oppblueprint . "." . $playerturn . ".";
		$playerrequestid = getLastRequestId($link, $playerphone);
		if($playerrequestid == 'none')
		{
			$textcontent = $textcontent . "SEND.";
			echo "<br>Used SEND";
			sendText($playerphone, $textcontent);
		}
		else
		{
			$textcontent = $textcontent . "REPLY.";
			echo "<br>Used REPLY<br>";
			echo $playerrequestid;
			replyText($playerphone, $textcontent, $playerrequestid);
		}
	}
	function recordReceivedSMS($link, $message_type, $message, $mobile_number, $shortcode, $timestamp, $request_id) //test method
	{
		$sqlcommand = 	"CREATE TABLE IF NOT EXISTS Received
						(
							id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
							messagetype VARCHAR(8),
							message VARCHAR(160),
							mobilenumber VARCHAR(12),
							shortcode VARCHAR(11),
							requestid VARCHAR(128),
							timestamp VARCHAR(16)
						)";
		if(mysqli_query($link, $sqlcommand))
		{
			echo 'Received database created/accessed. <br>';
		}
		else
		{
			echo 'Received database WAS NOT created/accessed. <br>';
		}
		$sqlcommand = 	"INSERT INTO Received(messagetype, message, mobilenumber, shortcode, requestid, timestamp) 
						VALUES('$message_type', '$message', '$mobile_number', '$shortcode', '$request_id', '$timestamp')";
		if(mysqli_query($link, $sqlcommand))
		{
			echo 'SMS recorded into database. <br>';
		}
		else
		{
			die('SMS was NOT recorded into database: ' . mysqli_error($link));
		}
	}
	function recordAccess($link) //test method
	{
		$sqlcommand = 	"CREATE TABLE IF NOT EXISTS Access
						(
							id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
							accesstime VARCHAR(16),
							accesspage VARCHAR(32)
						)";
		if(mysqli_query($link, $sqlcommand))
		{
			echo 'Access database created/accessed. <br>';
		}
		else
		{
			echo 'Access database WAS NOT created/accessed. <br>';
		}
		$timeaccessed = time();
		$sqlcommand = 	"INSERT INTO Access(accesstime, accesspage) 
						VALUES('$timeaccessed', 'messagereceiver')";
		if(mysqli_query($link, $sqlcommand))
		{
			echo 'Access recorded into database. <br>';
		}
		else
		{
			die('Access was NOT recorded into database: ' . mysqli_error($link));
		}
	}
	function getLastRequestId($link, $phonenumber)
	{
		$requestid = 'none';
		$sqlcommand = "SELECT requestid FROM Received WHERE mobilenumber = '$phonenumber' AND requestid <> 'used'";
		$selection = mysqli_query($link, $sqlcommand);
		if (mysqli_num_rows($selection) > 0) 
		{
			$selectedrow = mysqli_fetch_assoc($selection);
			$requestid = $selectedrow["requestid"];
			mysqli_query($link, "UPDATE Received SET requestid = 'used' WHERE mobilenumber = '$phonenumber'"); 
		}
		return $requestid;
	}
	function verifyBlueprint($blueprintA) //UNTESTED
	{
		$A = str_split($blueprintA);
		$B = str_split($blueprintB); //UNDONE
		$difference = 0;
		for($x = 0; $x < $A.count(); $x++)
		{
			if($A[$x] != $B[$x])
			{
				$difference++;
			}
		}
		return (difference > 1);
	}
?>