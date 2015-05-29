<?php
	//PHP runs when player's text is sent to Chikka, and Chikka sends to your server & this script.
	//SecretData includes SQLHOST, SQLUSER, SQLPASS, SQLDB, SHORTCODE, CHIKKAURL, CLIENTID, SECRETKEY
	include 'SecretData.php';
	
	$sqlink = selectBattleshipsDB(connectSQL());
	recordAccess($sqlink); //Recording access for debugging purposes.
	
	//try block puts POST values to non-global variables.
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
	
	recordReceivedSMS($sqlink, $message_type, $message, $mobile_number, $shortcode, $timestamp, $request_id); //Records all SMS details for replyText function (and debugging purposes).
	
	$tokens = explode(".", $message); //Splits message data into pieces (named tokens), delimiter is a period character. e.g. "This.splits.by.periods"
	
	//IF blocks deal with the command token. Determines what message was received from player.
	if(strcmp($tokens[0], "CONNECT") == 0) //CONNECT is the player initiating a matchmaking.
	{
		queuePlayer($sqlink, $mobile_number, $tokens[1]); //Queues the player with his blueprint to the Queue table. Can be identified with phone number.
		matchPlayers($sqlink); //Checks if Queue table has two players and then pairs them and moves them to the Playing table.
	}
	elseif(strcmp($tokens[0], "ACTION") == 0) //ACTION is the player making a move in the game. Expected to be in the Player table by now.
	{
		actionPlayer($sqlink, findPlayerID($sqlink, $mobile_number), $tokens[1], $tokens[2]); //Records the player's action, with the opponent's new blueprint and the player's new turn.
		if(allShipsLost($tokens[1])) //Checks if the opponent's blueprint has any ships left.
		{
			endPlayer($sqlink, findPlayerID($sqlink, $mobile_number)); //Removes the players from the playing queue. Win states are done on the Android app.
		}
	}
	elseif(strcmp($tokens[0], "FORFEIT") == 0) //FORFEIT is the player giving up.
	{
		forfeitPlayer($sqlink, findPlayerID($sqlink, $mobile_number)); //Sends the opponent a forfeit notice by the player. Removes the players from the Playing table.
	}
	else //Unknown command
	{
		replyText($mobile_number, "Command not recognized!", getLastRequestId($sqlink, $mobile_number)); //Tells the player that his text is invalid.
	}
	mysqli_close($sqlink); 
	exit(0);
	
	//Below are the functions; first few are relevant to Chikka.
	
	function replyText($phone, $message, $requestid)
	{
		if (!is_numeric($phone) && !mb_check_encoding($phone, 'UTF-8')) 
		{
			trigger_error('Phone number needs to be a valid UTF-8 encoded string');
			return false;
		}
		if (!mb_check_encoding($message, 'UTF-8')) 
		{
			trigger_error('Message needs to be a valid UTF-8 encoded string');
			return false;
		}
		$messageid = time() . $phone;
		$message = urlencode($message);
		$sendData = array(
			'mobile_number' => $phone,
			'message_id' => $messageid,
			'message' => $message,
		);
		if($requestid == 'none')
		{
			$sendData = array_merge($sendData, 
				array
				(
					'message_type' => 'SEND',
				)
			);
		}
		else
		{
			$sendData = array_merge($sendData, 
				array
				(
					'message_type' => 'REPLY',
					'request_id' => $requestid,
					'request_cost' => 'FREE'
				)
			);
		}
		return sendChikka($sendData);
	}
	function sendChikka($data)
	{
		
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
	
	
	function connectSQL()
	{
		$link = mysqli_connect(SQLHOST,SQLUSER,SQLPASS); 
		if (!$link) 
		{ 
			die('Could not connect to MySQL: ' . mysqli_error()); 
		} 
		return $link;
	}
	function createBattleshipsDB($link)
	{
		$sqlcommand = "CREATE DATABASE IF NOT EXISTS ".SQLDB;
		if (!(mysqli_query($link, $sqlcommand))) 
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
		if (!(mysqli_query($link, $sqlcommand))) 
		{
			echo "Error creating table: " . mysqli_error($link);
		} 
	}
	function queuePlayer($link, $phonenumber, $blueprint)
	{
		createQueue($link);
		$sqlcommand = 	"INSERT INTO Queue(phone, blueprint) 
						VALUES('$phonenumber', '$blueprint')";
		if(!(mysqli_query($link, $sqlcommand)))
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
	}
	function dequeuePlayer($link, $id)
	{
		$sqlcommand = "DELETE FROM Queue WHERE id = $id";
		if(!(mysqli_query($link, $sqlcommand)))
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
		if (!(mysqli_query($link, $sqlcommand))) 
		{
			echo "Error creating table: " . mysqli_error($link);
		} 
	}
	function playPlayer($link, $id, $phonenumber, $blueprint, $oppid, $turnstart)
	{
		$sqlcommand = 	"INSERT INTO Playing(id, phone, blueprint, oppid, turn) 
						VALUES($id, '$phonenumber', '$blueprint', $oppid, $turnstart)";
		if(!(mysqli_query($link, $sqlcommand)))
		{
			echo "Error occured when creating record for playing table";
		}
	}
	function updatePlayer($link, $id)
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
		replyText($playerphone, $textcontent, $playerrequestid);
	}
	function actionPlayer($link, $id, $oppblueprint, $turn)
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
		
		if($turn > $previousturn)
		{
			$messagecontent = "UPDATE." . $oppblueprint . ".";
			replyText($oppphone, $messagecontent, getLastRequestId($link, $oppphone));
			mysqli_query($link, "UPDATE Playing SET turn = $turn WHERE id = $id");
			mysqli_query($link, "UPDATE Playing SET blueprint = '$oppblueprint' WHERE id = $oppid");
		}
		else
		{
			echo "ERROR: Turn is not greater than player's previous turn.";
		}
	}
	function forfeitPlayer($link, $id)
	{
		$sqlcommand = "SELECT phone, oppid FROM Playing WHERE id = $id";
		$selection = mysqli_query($link, $sqlcommand);
		if (mysqli_num_rows($selection) > 0) 
		{
			$selectedrow = mysqli_fetch_assoc($selection);
			$phone = $selectedrow["phone"];
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
		replyText($oppphone, "FORFEIT.", getLastRequestId($link, $phone));
		endPlayer($link, $id);
	}
	function endPlayer($link, $id)
	{
		$sqlcommand = "SELECT oppid FROM Playing WHERE id = $id";
		$selection = mysqli_query($link, $sqlcommand);
		if (mysqli_num_rows($selection) > 0) 
		{
			$selectedrow = mysqli_fetch_assoc($selection);
			$oppid = $selectedrow["oppid"];
		}
		$sqlcommand = "DELETE FROM Playing WHERE id = $id";
		if(!(mysqli_query($link, $sqlcommand)))
		{
			echo "Error occured when removing player from Playing";
		}
		$sqlcommand = "DELETE FROM Playing WHERE id = $oppid";
		if(!(mysqli_query($link, $sqlcommand)))
		{
			echo "Error occured when removing opponent from Playing";
		}
	}
	function recordReceivedSMS($link, $message_type, $message, $mobile_number, $shortcode, $timestamp, $request_id)
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
		if(!(mysqli_query($link, $sqlcommand)))
		{
			echo 'Received database WAS NOT created/accessed. <br>';
		}
		$sqlcommand = 	"INSERT INTO Received(messagetype, message, mobilenumber, shortcode, requestid, timestamp) 
						VALUES('$message_type', '$message', '$mobile_number', '$shortcode', '$request_id', '$timestamp')";
		if(!(mysqli_query($link, $sqlcommand)))
		{
			die('SMS was NOT recorded into database: ' . mysqli_error($link));
		}
	}
	function recordAccess($link)
	{
		$sqlcommand = 	"CREATE TABLE IF NOT EXISTS Access
						(
							id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
							accesstime VARCHAR(16),
							accesspage VARCHAR(32)
						)";
		if(!(mysqli_query($link, $sqlcommand)))
		{
			echo 'Access database WAS NOT created/accessed. <br>';
		}
		$timeaccessed = time();
		$sqlcommand = 	"INSERT INTO Access(accesstime, accesspage) 
						VALUES('$timeaccessed', 'messagereceiver')";
		if(!(mysqli_query($link, $sqlcommand)))
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
			mysqli_query($link, "UPDATE Received SET requestid = 'used' WHERE requestid = '$requestid'"); 
		}
		return $requestid;
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
	function allShipsLost($blueprint)
	{
		return (substr_count($blueprint, '1') == 0);
	}
?>