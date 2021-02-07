<?php

class database_mysqli
{
	private $link;

	function __construct($host, $database, $user, $password)
	{
		$this->link = new mysqli($host, $user, $password, $database);

		/* check connection */
		if ($this->getLink()->connect_errno)
		{
			error_log("Connect failed: ".$this->getLink()->connect_error,1);
			exit();
		}

		if (!$this->getLink()->set_charset("utf8mb4")) {
			printf("Error loading character set utf8mb4: %s\n", $this->getLink()->error);
			exit();
		} else {
		}
	}

	/**
	 * @return \mysqli
	 */
	private function getLink()
	{
		return $this->link;
	}

	function close()
	{
		if ($this->link)
		{
			$this->getLink()->close();
		}
	}

	function query($_q, bool $handleError = true)
	{
		$query = $this->getLink()->query($_q);
		if (!$query)
		{
			if ($handleError)
				$this->handleError($_q, $this->getLink()->error);
		}

		return $query;
	}

	function handleError($query, $errorMessage)
	{

		log::log("SQL_EXCEPTION", ["query" => $query, "message" => $errorMessage], 5);

		$n = new \pulse\pulsemail();
		$n->setSubject(PAGE_NAME." - SQL Exception");
		$n->setText("<h4>".$errorMessage."</h4><br />".
			"<b>Query</b>: <br /><pre>".$query."</pre><br /><br />".
			"<b>Error</b>: <br /><pre>".$errorMessage."</pre><br /><br />".
			"<br />".
			"Server: <br /><pre>".print_r($_SERVER, true)."</pre><br /><br />".
			"Session: <br /><pre>".print_r($_SESSION, true)."</pre><br /><br />".
			"Post: <br /><pre>".print_r($_POST, true)."</pre><br /><br />".
			"Get: <br /><pre>".print_r($_GET, true)."</pre><br /><br />".
			"");
		$n->setRecipient("cp@pulseone.at", "Christopher Pfaffinger");
		$n->send();

		throw new \RuntimeException("Database Error. Please call Support!");
	}

	function get($_q, $single = false)
	{
//		$start = microtime(true);

		$result = $this->getLink()->query($_q);
		if ($result)
		{
			$r = [];
			if ($single)
			{
				return $result->fetch_assoc();
			}

			while ($row = $result->fetch_assoc())
			{
				$r[] = $row;
			}
			$result->free();
//			sl("QUERY ".str_replace(array("\r\n","\r","\n","\t"), " ", $_query)." TOOK ".(microtime(true)-$start)."ms");
			return $r;
		}
		else
		{
			$this->handleError($_q, $this->getLink()->error);
			return false;
		}
	}

	function filter($_t)
	{
		return $this->getLink()->real_escape_string($_t);
	}

	/**
	 * @param $_q
	 *
	 * @return int
	 */
	function count($_q)
	{
		if ($result = $this->getLink()->query($_q))
		{
			$row_cnt = $result->num_rows;
			$result->close();
			return (int)$row_cnt;
		}
	}

	/**
	 * @param string $s
	 *
	 * @return string
	 */
	function quotedStringOrNull(string $s)
	{
		if (strlen(trim($s)) <1)
			return "NULL";
		return "\"".$s."\"";

	}

	/**
	 * @param $s
	 *
	 * @return int|string
	 */
	function intOrNull($s)
	{
		if (!is_numeric($s))
			return "NULL";
		if (is_bool($s))
			return "NULL";
		return (int)$s;

	}
}

?>