<?php
/**
 * Flight booking system for RFE or similar events.
 * Created by Donat Marko (IVAO VID 540147) 
 * Any artwork/content displayed on IVAO is understood to comply with the IVAO Intellectual Property Policy (https://doc.ivao.aero/rules2:ipp)
 * @author Donat Marko
 * @copyright 2021 Donat Marko | www.donatus.hu
 */

/**
 * Representing one particular flight
 */
class Flight
{
	/**
	 * Finding and returning flight based on its ID.
	 * If flight doesn't exist, returns null.
	 * @param int $id
	 * @return Flight
	 */
	public static function Find($id)
	{
		global $db;
		if ($query = $db->GetSQL()->query("SELECT * FROM flights WHERE id=" . $id))
		{
			if ($row = $query->fetch_assoc())
				return new Flight($row);
		}
		return null;
	}

	/**
	 * Finding and returning flight based on its booking token.
	 * If flight doesn't exist, returns null.
	 * @param string $token
	 * @return Flight
	 */
	public static function FindToken($token)
	{
		global $db;
		if ($query = $db->GetSQL()->query("SELECT * FROM flights WHERE token='" . $token . "'"))
		{
			if ($row = $query->fetch_assoc())
				return new Flight($row);
		}
		return null;
	}

	/**
	 * Gets all flights from the database
	 * @return Flight[] 
	 */
	public static function GetAll()
	{
		global $db;
		$flts = [];

		if ($query = $db->GetSQL()->query("SELECT * FROM flights ORDER BY departure_time, flight_number"))
		{
			while ($row = $query->fetch_assoc())
				$flts[] = new Flight($row);
		}
		return $flts;
	}

	/**
	 * Converts all flights to JSON format
	 * Used by the admin area through AJAX
	 * @return string JSON
	 */
	public static function ToJsonAll($full = false)
	{
		$flts = [];
		foreach (Flight::GetAll() as $flt)
		{
			if ($full)
				$flts[] = json_decode($flt->ToJson(), true);
			else
				$flts[] = json_decode($flt->ToJsonLite(), true);
		}
		return json_encode($flts);
	}

	/**
	 * Creates a new flight.
	 * @param string[] $array normally $_POST
	 * @return int error code: 0 = no error, 403 = forbidden (not logged in or not admin), -1 = other error, 1 = booker user does not exist
	 */
	public static function Create($array)
	{
		global $db;
		if (Session::LoggedIn() && Session::User()->permission > 1)
		{
			if ($array["booked"] > 0 && !User::Find($array["booked_by"]))
				return 1;

			$depTime = $array["departure_estimated"] == "true" ? "null" : $array["departure_time"];
			$arrTime = $array["arrival_estimated"] == "true" ? "null" : $array["arrival_time"];

			if ($db->GetSQL()->query("INSERT INTO flights (flight_number, callsign, origin_icao, destination_icao, departure_time, arrival_time, aircraft_icao, aircraft_freighter, terminal, gate, route, booked, booked_by, booked_at) VALUES ('" . $array["flight_number"] . "', '" . $array["callsign"] . "', '" . $array["origin_icao"] . "', '" . $array["destination_icao"] . "', '" . $depTime . "', '" . $arrTime . "', '" . $array["aircraft_icao"] . "', " . $array["aircraft_freighter"] . ", '" . $array["terminal"] . "', '" . $array["gate"] . "', '" . $array["route"] . "', " . $array["booked"] . ", " . $array["booked_by"] . ", NOW())"))
				return 0;
		}
		else
			return 403;
		return -1;
	}

	/**
	 * This function processes the received flight booking token and acts accordingly
	 * Called at main
	 */
	public static function TokenProcessing()
	{
		global $page;
		
		if ($page == "token" && isset($_GET["id"]))
		{
			if (Session::LoggedIn())
				$page = "mybookings";
			else
				$page = "";

			if ($flt = Flight::FindToken($_GET["id"]))
			{
				$result = $flt->Book();
				switch ($result)
				{
					case 0:
						Pages::AddJSinline('
							swal2({
								title: "Flight booking has been confirmed!",
								text: "We\'re waiting for you on the event!",
								type: "success",
								confirmButtonText: "YAY!",
								timer: 5000,
							});
						');
						break;
					case 403:
						Pages::AddJSinline('
							swal2({
								title: "You have no access to confirm the booking!",
								text: "If you are logged in with other VID than the original booker, please log out!",
								type: "error",
								confirmButtonText: "RIP",
								timer: 5000,
							}).then((value) => { window.location.href="mybookings"; });
						');
						break;
					default:
						Pages::AddJSinline('
							swal2({
								title: "Error while confirming the flight!",
								text: "An unknown error has happened during the process. Please try again!",
								type: "error",
								confirmButtonText: "RIP",
								timer: 5000,
							}).then((value) => { window.location.href="mybookings"; });
						');
						break;
				}
				
			}
			else
			{
				Pages::AddJSinline('
					swal2({
						title: "No flight has been found with the given token!",
						text: "Probably the flight has already been confirmed, or the token is invalid.",
						type: "error",
						confirmButtonText: "RIP",
						timer: 5000,
					}).then((value) => { window.location.href="mybookings"; });
				');
			}
		}
	}

	/**
	 * Decides whether the given ICAO callsign represents a commercial flight or not (e.g. registration)
	 * @param string $callsign
	 * @return bool
	 */
	public static function isCommercialCallsign($callsign)
	{
		if (strlen($callsign) > 3)
		{
			$ok = true;
			for ($i = 0; $i < 3; $i++)
			{
				// if the first 3 characters are letters or not
				if (!preg_match("/^[a-zA-Z]+$/", $callsign[$i]))
					$ok = false;
				
				// if the 4th digit is number or not
				if (!is_numeric($callsign[3]))
					$ok = false;
			}
			if ($ok)
				return true;
		}
		return false;
	}

	/**
	 * Re-sends flight confirmation emails to the members who have set their email on their profile, and their flight is prebooked
	 * @return int error code - 0: no errors, 403: forbidden (user not admin), 1: one or more errors has happened during email sending, -1: other error
	 */
	public static function ResendConfirmationEmails()
	{
		if (Session::LoggedIn() && Session::User()->permission > 1)
		{
			$flts = [];
			foreach (Flight::GetAll() as $flt)
			{
				if ($flt->booked == "prebooked")
				{
					if ($user = User::Find($flt->bookedBy))
					{
						if (!empty($user->email))
							$flts[] = $flt;
					}
				}
			}

			$error = false;
			foreach ($flts as $flt)
			{
				if ($flt->SendConfirmationEmail() != 0)
					$error = true;
			}

			if ($error)
				return 1;
			else
				return 0;
		}
		else
			return 403;
		return -1;
	}

	public $id, $flightNumber, $callsign, $aircraftIcao, $aircraftFreighter, $originIcao, $destinationIcao, $departureTime, $isDepartureEstimated, $arrivalTime, $isArrivalEstimated, $terminal, $gate, $route;
	public $booked, $bookedAt, $bookedBy, $token;
	/**
	 * @param array $row - associative array from fetch_assoc()
	 */
	public function __construct($row)
	{
		$this->id = (int)$row["id"];
		$this->flightNumber = $row["flight_number"];
		$this->callsign = $row["callsign"];
		$this->aircraftIcao = $row["aircraft_icao"];
		$this->aircraftFreighter = $row["aircraft_freighter"] == 1;
		$this->originIcao = $row["origin_icao"];
		$this->destinationIcao = $row["destination_icao"];
		$this->terminal = $row["terminal"];
		$this->gate = $row["gate"];
		$this->route = $row["route"];
		$this->bookedAt = $row["booked_at"];
		$this->bookedBy = (int)$row["booked_by"];
		$this->token = $row["token"];
		$this->isDepartureEstimated = false;
		$this->isArrivalEstimated = false;

		switch ($row["booked"])
		{
			case 1:
				$this->booked = "prebooked";
				break;
			case 2:
				$this->booked = "booked";
				break;
			default:
				$this->booked = "free";
				break;
		}

		if ($row["departure_time"] != 0 && $row["arrival_time"] == 0)
		{
			$this->isArrivalEstimated = true;
			$this->departureTime = $row["departure_time"];
			$this->arrivalTime = date("Y-m-d H:i:s", strtotime($this->departureTime) + $this->getCalculatedEET());
		}
		elseif ($row["departure_time"] == 0 && $row["arrival_time"] != 0)
		{
			$this->isDepartureEstimated = true;
			$this->arrivalTime = $row["arrival_time"];	
			$this->departureTime = date("Y-m-d H:i:s", strtotime($this->arrivalTime) - $this->getCalculatedEET());
		}
		else
		{		
			$this->departureTime = $row["departure_time"];
			$this->arrivalTime = $row["arrival_time"];	
		}
	}

	/**
	 * Returns the actual EET if neither arrival nor departure times are estimated.
	 * @return int [seconds]
	 */
	public function getActualEET()
	{
		if ($this->isArrivalEstimated || $this->isDepartureEstimated)
			return null;

		return strtotime($this->arrivalTime) - strtotime($this->departureTime);
	}
	
	/**
	 * Returns the calculated EET based on the aircraft performance and great circle distance.
	 * @return int [seconds]
	 */
	public function getCalculatedEET()
	{
		global $dbNav;

		$ori = $this->getOrigin();
		$des = $this->getDestination();
		if (!$ori || !$des)
			return null;

		$gcd = haversineGreatCircleDistance($ori->latitude, $ori->longitude, $des->latitude, $des->longitude, 3440);
		$speed = 250;

		if ($query = $dbNav->GetSQL()->query("SELECT * FROM aircrafts where icao='" . $this->aircraftIcao . "'"))
		{
			if ($row = $query->fetch_assoc())
			{
				if ($row["speed"] > 0)
					$speed = (int)$row["speed"];
			}
		}

		// some correction (for climb and descent)
		$t = round(($gcd / $speed + 0.66) * 3600);
		return $t;
	}

	/**
	 * Forms ICAO airline code from the first 3 characters of the callsign and returns the Airline object, otherwise returns null.
	 * @return Airline
	 */
	public function getAirline()
	{
		if (Flight::isCommercialCallsign($this->callsign))
			return Airline::Find(substr($this->callsign, 0, 3));
		return null;
	}
	
	/**
	 * Returns the Airport object of destination, otherwise returns null.
	 * @return Airport
	 */
	public function getDestination()
	{
		return Airport::Find($this->destinationIcao);
	}
	
	/**
	 * Returns the Airport object of origin, otherwise returns null.
	 * @return Airport
	 */
	public function getOrigin()
	{
		return Airport::Find($this->originIcao);
	}
	
	/**
	 * Creates the content of "position" field to frontend/email
	 * Terminal or gate = "TBD"
	 * both empty => question mark / "no data available"
	 * @param bool $graphical if false, it will be text only for emails, otherwise Bootstrap 4 badges for UI/UX
	 * @return string
	 */
	public function getPosition($graphical = true)
	{
		$t = "";
		$g = "";
		
		if ($graphical)
		{
			if (empty($this->terminal) && empty($this->gate))
				$t = '<span class="badge badge-danger" data-toggle="tooltip" data-placement="top" title="No data available"><i class="fas fa-question"></i></span>';
			else
			{
				if (!empty($this->terminal))
				{
					if ($this->terminal === "TBD")
						$t = '<span class="badge badge-warning" data-toggle="tooltip" data-placement="top" title="Terminal: to be determined"><i class="far fa-building"></i> TBD</span> ';
					else
						$t = '<span class="badge badge-primary" data-toggle="tooltip" data-placement="top" title="Terminal"><i class="far fa-building"></i> ' . $this->terminal . '</span> ';
				}
				if (!empty($this->gate))
				{
					if ($this->gate === "TBD")
						$g = '<span class="badge badge-warning" data-toggle="tooltip" data-placement="top" title="Gate: to be determined"><i class="fas fa-plane"></i> TBD</span>';
					else
						$g = '<span class="badge badge-secondary" data-toggle="tooltip" data-placement="top" title="Gate"><i class="fas fa-plane"></i> ' . $this->gate . '</span>';								
				}
			}						
		}
		else
		{
			if (empty($this->terminal) && empty($this->gate))
				$t = '(no data available)';
			else
			{
				if (!empty($this->terminal))
				{
					if ($this->terminal === "TBD")
						$t = 'Terminal: to be determined ';
					else
						$t = 'Terminal: ' . $this->terminal;
				}
				if (!empty($this->gate))
				{
					if (!empty($t))
						$t .= ' / ';

					if ($this->gate === "TBD")
						$g = 'Gate: to be determined';
					else
						$g = 'Gate: ' . $this->gate;
				}
			}						
		}

		return $t . $g;
	}
	
	/**
	 * Returns the name of the aircraft from the NAV database
	 * @return string
	 */
	public function getAircraftName()
	{
		global $dbNav;
		if ($query = $dbNav->GetSQL()->query("SELECT * FROM aircrafts WHERE icao='" . $this->aircraftIcao . "'"))
		{
			if ($row = $query->fetch_assoc())
			{
				if ($this->aircraftFreighter)
					return $row["name"] . " (freighter)";
				else
					return $row["name"];
			}
		}
		return "";
	}
	
	/**
	 * Converts the object fields to JSON, also adds the additional data from functions
	 * Used by the JSON AJAX request
	 * @return string JSON
	 */
	public function ToJson()
	{
		global $config;
		$flight = (array)$this;

		$ori = $this->getOrigin();
		$des = $this->getDestination();
		$airline = $this->getAirline();
		
		$data = [		
			"departureTimeHuman" => getHumanDateTime($this->departureTime),
			"arrivalTimeHuman" => getHumanDateTime($this->arrivalTime),
			"position" => $this->getPosition(),
			"bookedAtHuman" => $this->booked !== "free" ? getHumanDateTime($this->bookedAt) : null,
			"bookedByUser" => $this->booked !== "free" ? json_decode(User::Find($this->bookedBy)->ToJson(false)) : null,
			"sessionUser" => Session::LoggedIn() ? json_decode(Session::User()->ToJson(false)) : null,
			"airline" => $airline ? json_decode($airline->ToJson()) : null,
			"originAirport" => $ori ? json_decode($ori->ToJson()) : null,
			"destinationAirport" => $des ? json_decode($des->ToJson()) : null,
			"aircraftName" => $this->getAircraftName(),
			"wxUrl" => $config["wx_url"],
			"greatCircleDistanceNm" => $this->getGreatCircleDistance(),
			"turnoverFlights" => $this->getTurnoverFlights(true),
		];

		return json_encode(array_merge($flight, $data));
	}

	/**
	 * Converts the object fields to JSON, also adds the a few additional data from functions
	 * Used by the ToJsonAll() through AJAX request
	 * @return string JSON
	 */
	public function ToJsonLite()
	{
		$flight = (array)$this;
		$ori = $this->getOrigin();
		$des = $this->getDestination();
		$airline = $this->getAirline();

		$data = [
			"position" => $this->getPosition(),
			"airline" => $airline ? json_decode($airline->ToJson()) : null,
			"originAirport" => $ori ? json_decode($ori->ToJson()) : null,
			"destinationAirport" => $des ? json_decode($des->ToJson()) : null,
		];
		return json_encode(array_merge($flight, $data));
	}

	/**
	 * Sends flight booking confirmation email
	 * @return int error code of Email::Prepare() or: 403: forbidden (not booker user or not admin), -1: other error
	 */
	public function SendConfirmationEmail()
	{
		if (Session::LoggedIn())
		{
			$sesUser = Session::User();
			if ($sesUser->permission > 1 || $sesUser->vid == $this->bookedBy)
			{
				$u = User::Find($this->bookedBy);
				$email = $this->EmailReplaceVars(file_get_contents("contents/flight_booking.html"));
				if (Email::Prepare($email, $u->firstname . " " . $u->lastname, $u->email, "Flight booking confirmation"))
					return 0;
			}
			else
				return 403;
		}
		else
			return 403;
		return -1;
	}
	
	/**
	 * Prebooks the flight if it has not been booked yet and we're logged in
	 * Checks whether the user already has one or more booked flights to the concerned timeframe or not. If has, booking will be failed
	 * Generates a token for confirming booking and sends confirmation email
	 * @return string JSON error code: 0 = no error, 1 = flight is not free, 2 = conflict with other booking, -1 = other error
	 */
	public function Prebook()
	{
		global $db;

		if (Session::LoggedIn())
		{
			$u = Session::User();
			
			if ($this->booked == "free")
			{
				// checking conflicts
				$bookeds = [];
				$conflictings = [];
				foreach ($u->getBookedFlights() as $flt)
					$bookeds[] = $flt->id;
				foreach ($this->getConflictingFlights() as $flt)
					$conflictings[] = $flt->id;
				
				$trueConflicts = array_intersect($bookeds, $conflictings);
				if ($trueConflicts && count($trueConflicts) > 0)
				{
					// if the present flight conflicts with one of the booked ones
					$callsigns = [];
					foreach ($trueConflicts as $id)
						$callsigns[] = Flight::Find($id)->callsign;

					echo json_encode(["error" => 2, "callsigns" => implode(", ", $callsigns)]);
					die();
				}
				else
				{
					$token = md5(uniqid($u->vid . date("Y-m-d H:i:s")));
					$query = "UPDATE flights SET booked=1, booked_by=" . $u->vid .", booked_at=now(), token='" . $token . "' WHERE id=" . $this->id;
					$this->token = $token;
					$this->bookedBy = $u->vid;

					if ($db->GetSQL()->query($query))
					{					
						if (!empty($u->email))
						{
							if ($this->SendConfirmationEmail() === 0)
								return 0;
						}
						else
							return 0;
					}
				}
			}
			else
				return 1;
		}
		else
			return 403;
		return -1;
	}

	/**
	 * Books the flight if it has already been prebooked AND we're logged out, or logged in as the booker, or we're admins
	 * @return int error code: 0 = no error, 1 = flight is not prebooked, 403 = we are not the bookers or admins, -1 = other error
	 */
	public function Book()
	{
		global $db;		
		$u = Session::User();
		
		if (!$u || $u && ($u->vid = $this->bookedBy || $u->permission > 1))
		{	
			if ($this->booked == "prebooked")
			{
				$query = "UPDATE flights SET booked=2, token='' WHERE id=" . $this->id;
				if ($db->GetSQL()->query($query))
					return 0;
			}
			else
				return 1;
		}
		else
			return 403;
		return -1;
	}

	/**
	 * Deletes the current booking (makes the flight free again) if the flight has been booked by the current user or we are admins
	 * @return string JSON error code: 0 = no error, 403 = no permission, -1 = other error
	 */
	public function Free()
	{
		global $db;
		
		if (Session::LoggedIn())
		{
			$u = Session::User();
			
			// only allow freeing if we are admins or the previous booker
			if ($u->permission >= 2 || $u->vid == $this->bookedBy)
			{			
				$query = "UPDATE flights SET booked=0, booked_by=0, booked_at=now(), token='' WHERE id=" . $this->id;
				if ($db->GetSQL()->query($query))
					return 0;
			}
			else
				return 403;
		}
		else
			return 403;
		return -1;
	}

	/**
	 * Deletes the flight.
	 * @return int error code: 0 = no error, 403 = forbidden (not logged in or not admin), -1 = other error
	 */
	public function Delete()
	{
		global $db;
		if (Session::LoggedIn() && Session::User()->permission > 1)
		{
			if ($db->GetSQL()->query("DELETE FROM flights WHERE id=" . $this->id))
				return 0;
		}
		else
			return 403;
		return -1;
	}

	/**
	 * Modifies the flight.
	 * Updating bookedAt only if there was a change in the booking status
	 * @param string[] $array normally $_POST
	 * @return int error code: 0 = no error, 403 = forbidden (not logged in or not admin), -1 = other error, 1 = booker user does not exist
	 */
	public function Update($array)
	{
		global $db;
		if (Session::LoggedIn() && Session::User()->permission > 1)
		{
			if ($array["booked"] > 0 && !User::Find($array["booked_by"]))
				return 1;

			$depTime = $array["departure_estimated"] == "true" ? "null" : $array["departure_time"];
			$arrTime = $array["arrival_estimated"] == "true" ? "null" : $array["arrival_time"];

			if ($array["booked_by"] == $this->bookedBy)
				$sql = "UPDATE flights SET flight_number='" . $array["flight_number"] . "', callsign='" . $array["callsign"] . "', origin_icao='" . $array["origin_icao"] . "', destination_icao='" . $array["destination_icao"] . "', departure_time='" . $depTime . "', arrival_time='" . $arrTime . "', aircraft_icao='" . $array["aircraft_icao"] . "', aircraft_freighter=" . $array["aircraft_freighter"] . ", terminal='" . $array["terminal"] . "', gate='" . $array["gate"] . "', route='" . $array["route"] . "', booked=" . $array["booked"] . " WHERE id=" . $array["id"];
			else
				$sql = "UPDATE flights SET flight_number='" . $array["flight_number"] . "', callsign='" . $array["callsign"] . "', origin_icao='" . $array["origin_icao"] . "', destination_icao='" . $array["destination_icao"] . "', departure_time='" . $depTime . "', arrival_time='" . $arrTime . "', aircraft_icao='" . $array["aircraft_icao"] . "', aircraft_freighter=" . $array["aircraft_freighter"] . ", terminal='" . $array["terminal"] . "', gate='" . $array["gate"] . "', route='" . $array["route"] . "', booked=" . $array["booked"] . ", booked_by=" . $array["booked_by"] . ", booked_at=NOW() WHERE id=" . $array["id"];

			if ($db->GetSQL()->query($sql))
				return 0;
		}
		else
			return 403;
		return -1;
	}

	/**
	 * Replaces the flight variables in the email text and returns the email text back
	 * @param string $email 
	 * @return string
	 */
	public function EmailReplaceVars($email)
	{
		$email = str_replace('%flt_flightNumber%', $this->flightNumber,                                                        $email);
		$email = str_replace('%flt_callsign%',     $this->callsign,                                                            $email);
		$email = str_replace('%flt_aircraft%',     $this->aircraftIcao . ($this->aircraftFreighter ? ' (freighter)' : ''),     $email);

		if ($this->isDepartureEstimated)
			$email = str_replace('%flt_departure%', $this->originIcao . ' (est: ' . getHumanDateTime($this->departureTime) . ')', $email);
		else
			$email = str_replace('%flt_departure%', $this->originIcao . ' (' . getHumanDateTime($this->departureTime) . ')', $email);

		if ($this->isArrivalEstimated)
			$email = str_replace('%flt_destination%', $this->destinationIcao . ' (est: ' . getHumanDateTime($this->arrivalTime) . ')', $email);
		else
			$email = str_replace('%flt_destination%', $this->destinationIcao . ' (' . getHumanDateTime($this->arrivalTime) . ')', $email);

		$email = str_replace('%flt_position%',     $this->getPosition(false),                                                  $email);
		$email = str_replace('%flt_token%',        $this->token,                                                               $email);
		$email = str_replace('%flt_route%',        $this->route,                                                               $email);
		
		$user = User::Find($this->bookedBy);
		if ($user)
		{
			$email = str_replace('%flt_bookerFirstname%', $user->firstname, $email);
			$email = str_replace('%flt_bookerLastname%',  $user->lastname,  $email);
		}
		return $email;
	}

	/**
	 * Returns the conflicting flights in regards to departure and arrival times and concerned airports
	 * Considered to be conflicting if:
	 *      ***********           ************    ******     *******  
	 *         ******            *********        ******         ******
 	 * @return Flight[]
	 */
	public function getConflictingFlights()
	{
		$flts = Flight::GetAll();
		$conflictings = [];
		$eventAptIcaos = [];

		foreach (EventAirport::GetAll() as $apt)
			$eventAptIcaos[] = $apt->icao;

		foreach ($flts as $flt)
		{
			if ($this->id != $flt->id)
			{
				foreach ($eventAptIcaos as $icao)
				{
					if (($this->originIcao == $icao || $this->destinationIcao == $icao) && ($flt->originIcao == $icao || $flt->destinationIcao == $icao))
					{
						$startA = strtotime($this->departureTime);
						$endA = strtotime($this->arrivalTime);
						$startB = strtotime($flt->departureTime);
						$endB = strtotime($flt->arrivalTime);

						if ($startA <= $endB && $endA >= $startB)
							$conflictings[] = $flt;
					}
				}
			}
		}

		return $conflictings;
	}

	/**
	 * Returns the turnover flights of this flight.
	 * Flights are considered to be turnovers, if flight numbers are adjacent, timeframes don't collapse and airports are swapped
	 * @return Flight[]
	 */
	public function getTurnoverFlights($toJson = false)
	{
		$flts = Flight::GetAll();
		$turnovers = [];

		foreach ($flts as $flt)
		{
			// if origin and destination airports are swapped
			if ($this->originIcao == $flt->destinationIcao && $this->destinationIcao == $flt->originIcao)
			{
				$startA = strtotime($this->departureTime);
				$endA = strtotime($this->arrivalTime);
				$startB = strtotime($flt->departureTime);
				$endB = strtotime($flt->arrivalTime);
				$fltnoA = $this->flightNumber;
				$fltnoB = $flt->flightNumber;

				// if timeframes are not collapsing
				if (!($startA <= $endB && $endA >= $startB))
				{
					// if flight numbers are 3 characters or longer
					if (strlen($fltnoA) >= 3 && strlen($fltnoB) >= 3)
					{
						$fltnoA = substr($fltnoA, 2);
						$fltnoB = substr($fltnoB, 2);

						// if the trimmed flight numbers are numeric
						if (is_numeric($fltnoA) && is_numeric($fltnoB))
						{
							// if the flight numbers differs with +- 1
							if ($fltnoA + 1 == $fltnoB || $fltnoA - 1 == $fltnoB)
							{
								if ($toJson)
									$turnovers[] = json_decode($flt->ToJsonLite(), true);
								else
									$turnovers[] = $flt;
							}
						}
					}
				}
			}
		}
		return $turnovers;
	}

	public function getGreatCircleDistance()
	{
		$ori = $this->getOrigin();
		$des = $this->getDestination();

		if (!$ori || !$des)
			return null;

		return haversineGreatCircleDistance($ori->latitude, $ori->longitude, $des->latitude, $des->longitude, 3440);
	}
}
