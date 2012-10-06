<?php

/**
 @TODO: Caching.
 */
class Phergie_Plugin_Github extends Phergie_Plugin_Abstract_Command
{
	/**
	 * The Github url
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Endpoint for the Github API
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * The default project to query
	 *
	 * @var string
	 */
	private $default_project;

	/**
	 * Initializes the default settings
	 *
	 * @return void
	 */
	public function onInit()
	{
		$this->url = $this->getPluginIni('url');
		$this->api_url = $this->getPluginIni('api_url');
		$this->default_project = $this->getPluginIni('default_project');
	}

	public static function checkDependencies(Phergie_Driver_Abstract $client, array $plugins)
	{
		$errors = array();
/*		
		if (!extension_loaded('SimpleXML')) { // probably need to change this to JSON
			$errors[] = 'SimpleXML php extension is required';
		}
*/
		return empty($errors) ? true : $errors;
	}

	/**
	 * @return void
	 */
/*	public function onDoStats($days_ago = 1)
	{
		$stats = self::getTracStats($days_ago, $this->getIni('trac.url'), $this->getIni('trac.name'));
		if ($stats) {
			$this->doPrivmsg($this->event->getSource(), $stats);
		}
	}
*/
    /**
     * @return void
     */
/*    public function onDoStatsExtras($days_ago = 1)
    {
        $stats = self::getTracStats($days_ago, 'https://trac.habariproject.org/habari-extras', 'Habari Extras');
        if ($stats) {
            $this->doPrivmsg($this->event->getSource(), $stats);
        }
    }
*/
	
	public function onPrivmsg()
	{
		$channel = "#racerbot"; // make this dynamic.
		if ( $this->event->getSource() !== $channel ) {
			return;
		}
		$message = $this->event->getArgument(1);
		if ( preg_match("@^#(\d+)\b@", $message, $m) ) {
			$this->onDoIssue($m[1]);
		}
		elseif ( preg_match("@^commit ([a-f0-9]{4,})\b@", $message, $m) ) {
			$this->onDoChangeset($m[1]);
		}
		elseif ( preg_match("@^commit ([a-f0-9]{1,3})$@", $message, $m) ) {
			$this->doPrivmsg($this->event->getSource(), "That hash is too short. Four characters or more, please.");
		}
/*		elseif ( preg_match("@^rex(\d+)\b@", $message, $m) ) {
                        $this->onDoExtraChangeset($m[1]);
                }*/
		$this->processCommand($this->event->getArgument(1));
		unset( $message, $m );
	}
	/*
	public function onDoBlame($file, $line)
	{
		try {
			$file = escapeshellcmd($file);
			$blame = shell_exec("svn blame http://svn.habariproject.org/habari/trunk/htdocs/{$file}");
			$lines = split("\n", $blame);
			if ( $line < 0 || $line > (count($lines)+1) ) {
				$this->doPrivmsg($this->event->getSource(), "No line number {$line} in {$file}");
			}
			else {
				$this->doPrivmsg(
					$this->event->getSource(),
					sprintf("%s line %d: r%s", $file, $line, trim($lines[$line-1]))
				);
			}
		}
		catch (Exception $e) {
			$this->doPrivmsg($this->event->getSource(), "No file {$file}");
		}
	}
	*/
	public function onDoIssue($ticket)
	{
		try {
			$jsonurl = $this->getIni('github_habari.url')."/issues/{$ticket}";
			$json_output = json_decode(file_get_contents($jsonurl,0,null,null));
			$this->doPrivmsg($this->event->getSource(), sprintf( 'Habari Issue %s: %s -- %s', $ticket, $json_output->title, $json_output->html_url ));
		}
		catch (Exception $e) { // actually, this doesn't work. Probably should look for a false on the file_get_contents()
			echo $e->getMessage();
			$this->doPrivmsg($this->event->getSource(), "Sorry, could not find Issue {$ticket}.");
		}
	}

	public function onDoChangeset($rev)
	{
		$repo = "system";
		try {
			$jsonurl = $this->getIni('github_system.url')."/commits/{$rev}";

			$output = file_get_contents($jsonurl,0,null,null);
			if ( !$output ) {
				$output = file_get_contents( $this->getIni('github_habari.url')."/commits/{$rev}" );
				if ( !$output ) {
					$this->doPrivmsg($this->event->getSource(), "Isuck".strlen($html)."Sorry, could not find commit {$rev}.");
					return; // is this what we want to do? Maybe the logic here is all wrong.
				}
				$repo = "habari";
			}

			// ugh. Somebody make this work well and look pretty. Like using phergie.ini
			$json_output = json_decode( $output );
			$rev_url = "https://github.com/habari/{$repo}/commit/{$json_output->sha}";

			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf( 'Commit %s: %s... %s', $rev, substr( $json_output->commit->message, 0, 100), $rev_url)
			);
		}
		catch (Exception $e) {
			$this->doPrivmsg($this->event->getSource(), "Isuck".strlen($html)."Sorry, could not find changeset {$rev}.");
		}
	}

	/**
	 * Print information and link to the latest commit for a project
	 *
	 * @param project String The project in the form (user|org)/repo
	 */
	public function onDoRev($project = null) {
		$project = $project ?: $this->default_project;
		$project_url = "{$this->url}/{$project}";
		$api_url = $this->api_url;
		try {
			$json_url = "{$api_url}/repos/{$project}/commits?per_page=1";
			$output = file_get_contents($json_url,0,null,null);
			if ( !$output ) {
				$this->doPrivmsg($this->event->getSource(), "Something went wrong with that, sorry.");
				return;	
			}

			// it's a single-element array, grab the first item
			$json_output = current( json_decode( $output ) );

			$rev_hash = substr( $json_output->sha, 0, 8 ); // 8 characters should be safe, no?

			$rev_url = "{$project_url}/commit/{$rev_hash}";
			$rev_datetime = new DateTime($json_output->commit->committer->date);
			$rev_date = $rev_datetime->format( 'j F Y' );

			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf( 'Latest Commit: %s: %s... (%s) %s',
					$rev_hash,
					substr( $json_output->commit->message, 0, 100 ),
					$rev_date, $rev_url
				)
			);
		}
		catch (Exception $e) {
			$this->doPrivmsg($this->event->getSource(), "Something went wrong with that, sorry.");
		}
	}

	/**
	 * Reads last commit/ticket logs
	 */
/*	public static function getTracStats($days_ago, $url, $name)
	{
		switch ( $days_ago ) {
			case 'today':
			case 'day':
			case 1:
				$days_ago = 1;
				$verb = 'Today';
				break;
			case 'week':
				$days_ago = date('N');
				$verb = 'This week';
				break;
			case 'month':
				$days_ago = date('j');
				$verb = 'This month';
				break;
			case 'year':
				$days_ago = date('z');
				$verb = 'This year';
				break;
			case $days_ago > 0:
				$verb = sprintf('In the past %d days', $days_ago);
				break;
			default:
				return "I'm sorry, what the hell is a '{$days_ago}'?";
		}
		try {
			$logs = simplexml_load_string(
				self::getURL("{$url}/timeline?changeset=on&ticket=on&max=5000&daysback={$days_ago}&format=rss")
			);
		}
		catch (Exception $e) {
			return "Sorry, could not get stats.";
		}
		
		$commits = 0;
		$new = 0;
		$closed = 0;
		foreach ( $logs->channel->item as $item ) {
			switch ( (string) $item->category ) {
				case 'changeset':
					$commits++;
					break;
				case 'newticket':
					$new++;
					break;
				case 'closedticket':
					$closed++;
					break;
			}
		}
		
		$r = sprintf( '%s, %s has had %d commits, %d new tickets and %d closed tickets', $verb, $name, $commits, $new, $closed );
		unset($verb, $commits, $new, $closed, $logs);
		return $r;
	}
*/	
}

if ( !class_exists('Process') ) {
class Process
{
	public static function open ( $command )
	{
		$retval = '';
		$error = '';

		$descriptorspec = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'r')
		);

		$resource = proc_open($command, $descriptorspec, $pipes, null, $_ENV);
		if (is_resource($resource)) {
			$stdin = $pipes[0];
			$stdout = $pipes[1];
			$stderr = $pipes[2];

			while (! feof($stdout)) {
				$retval .= fgets($stdout);
			}

			while (! feof($stderr)) {
				$error .= fgets($stderr);
			}

			fclose($stdin);
			fclose($stdout);
			fclose($stderr);

			$exit_code = proc_close($resource);
		}

		if (! empty($error)) {
			throw new Exception($error);
		}
		else {
			return $retval;
		}
	}
}
}
