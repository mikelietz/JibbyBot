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
	 * The default project to query for issues
	 *
	 * @var string
	 */
	private $default_issues;

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
		$this->default_issues = $this->getPluginIni('default_issues') ?: $this->default_project;
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
	 * Print statistics for a project
	 *
	 * @param Integer $days_ago optional days back to look for stats
	 * @param String $project optional project in the form (user|org)/repo
	 */
	public function onDoStats($days_ago = 1, $project = null)
	{
		$issues_project = $project ?: $this->default_issues;
		$api_url = $this->api_url;

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
				$this->doPrivmsg(
					$this->event->getSource(),
					"I'm sorry, what the hell is a '{$days_ago}'?"
				);
				return;
		}

		$now = new DateTime();
		$since = $now->sub(new DateInterval("P{$days_ago}D"));

		try {
			$json_url = "{$api_url}/repos/{$issues_project}/issues";

			$json_output = json_decode(file_get_contents($json_url.'?since='.$since->format('c'),0,null,null));

			$opened = array_reduce($json_output, function($count, $issue) use ($since) {
				$created = new DateTime($issue->created_at);
				if ($created > $since) $count++;
				return $count;
			});
			$opened = $opened ?: 0;

			$json_output = json_decode(file_get_contents($json_url.'?since='.$since->format('c').'&state=closed',0,null,null));
			$closed = count($json_output);

			$commits_project = $project ?: $this->default_project;
			$json_url = "{$api_url}/repos/{$commits_project}/commits";
			$json_output = json_decode(file_get_contents($json_url.'?since='.$since->format('c').'&state=closed',0,null,null));
			$commits = count($json_output);

			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf(
					'%s, %s has had %d commits, %d new issues and %d closed issues',
					$verb, $project, $commits, $opened, $closed
				)
			);
		}
		catch (Exception $e) {
			$this->doPrivmsg($this->event->getSource(), "Something went wrong with that, sorry.");
		}
	}

	public function onPrivmsg()
	{
		$channels = explode(',', $this->getPluginIni('speak_channels'));
		if ( !in_array($this->event->getSource(), $channels) ) {
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

	/**
	 * Print information and link to an issue
	 *
	 * @param String $project optional project in the form (user|org)/repo
	 */
	public function onDoIssue($ticket, $project = null)
	{
		$project = $project ?: $this->default_issues;
		$api_url = $this->api_url;
		try {
			$json_url = "{$api_url}/repos/{$project}/issues/{$ticket}";
			$json_output = json_decode(file_get_contents($json_url,0,null,null));
			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf( 'Issue %s: %s -- %s',
					$ticket,
					$json_output->title,
					$json_output->html_url
				)
			);
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
	 * @param String $project optional project in the form (user|org)/repo
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
