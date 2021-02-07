<?php

class database_mysqli
{
	private $link;

	function __construct(string $host, string $user, string $password, string $database)
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
	public function getLink()
	{
		return $this->link;
	}

	public function close()
	{
		if ($this->link)
		{
			$this->getLink()->close();
		}
	}

	public function query($_q, bool $handleError = true)
	{
		$query = $this->getLink()->query($_q);
		if (!$query)
		{
			if ($handleError)
				$this->handleError($_q, $this->getLink()->error);
		}

		return $query;
	}

	public function handleError($query, $errorMessage)
	{

		echo PHP_EOL;
		echo "[FATAL] DATASBASE".PHP_EOL;
		echo "[SQL_EXCAPTION]".PHP_EOL;
		
		echo "**QUERY**".PHP_EOL;
		echo $query.PHP_EOL;
		echo PHP_EOL;
		echo "**ERRORMESSAGE**".PHP_EOL;
		echo $errorMessage.PHP_EOL;
		echo PHP_EOL;
		
		die();
	}

	public function get($_q, $single = false)
	{

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
			return $r;
		}
		else
		{
			$this->handleError($_q, $this->getLink()->error);
			return false;
		}
	}

	public function filter($_t)
	{
		return $this->getLink()->real_escape_string($_t);
	}

	/**
	 * @param $_q
	 *
	 * @return int
	 */
	public function count($_q)
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
	public function quotedStringOrNull(string $s)
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
	public function intOrNull($s)
	{
		if (!is_numeric($s))
			return "NULL";
		if (is_bool($s))
			return "NULL";
		return (int)$s;

	}
}

?>