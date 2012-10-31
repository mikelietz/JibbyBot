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
		$this->api = new GithubAPI($this->getPluginIni('api_url'));
		$this->url = $this->getPluginIni('url');
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
	 *
	 * @todo Handle errors
	 * @todo Handle paging
	 */
	public function onDoStats($days_ago = 1, $project = null)
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
			case 'fortnight':
				$days_ago = date('N')+7;
				$verb = 'This fortnight';
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
			$commits_project = $project ?: $this->default_project;
			$commits = count($this->api->commits($commits_project, array(
				'since' => $since,
			)));

			$issues_project = $project ?: $this->default_issues;
			$issues = $this->api->issues($issues_project, array(
				'since' => $since,
			));

			$opened = array_reduce($issues, function($count, $issue) use ($since) {
				$created = new DateTime($issue->created_at);
				if ($created > $since) $count++;
				return $count;
			});

			$closed = count($this->api->issues($issues_project, array(
				'since' => $since,
				'state' => 'closed'
			)));

			$message = sprintf(
				'%s, %s has had %d commits, %d new issues and %d closed issues',
				$verb, $issues_project, $commits, $opened, $closed
			);
			if ($commits_project != $issues_project) {
				$message = sprintf(
					'%s, %s has had %d commits, %s has had %d new issues and %d closed issues',
					$verb, $commits_project, $commits, $issues_project, $opened, $closed
				);
			}

			$this->doPrivmsg( $this->event->getSource(), $message );

			$milestone = $this->api->milestones($issues_project);

			if ( count($milestone) ) {
				$milestone = current($milestone);
				$milestone_url = $this->url."/{$issues_project}/issues?milestone=".$milestone->number;

				$milestone_message = "You can help!";
				if ($milestone->open_issues == 0) {
					$milestone_message = "Release!";
				}
				$message = sprintf(
					'Milestone %s has %d open issues. %s %s',
					$milestone->title, $milestone->open_issues, $milestone_message, $milestone_url
				);
				$this->doPrivmsg( $this->event->getSource(), $message );
			}

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
			$this->onDoCommit($m[1]);
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
	 * @param String $issue The issue to retrieve
	 * @param String $project optional project in the form (user|org)/repo
	 */
	public function onDoIssue($issue, $project = null)
	{
		$project = $project ?: $this->default_issues;
		try {
			$continue = false;
			$ticket = $this->api->issues($project, compact('issue', 'continue'));
			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf( 'Issue %s: %s -- %s',
					$issue,
					$ticket->title,
					$ticket->html_url
				)
			);
		}
		catch (Exception $e) { // actually, this doesn't work. Probably should look for a false on the file_get_contents()
			echo $e->getMessage();
			$this->doPrivmsg($this->event->getSource(), "Sorry, could not find Issue {$issue}.");
		}
	}

	public function onDoCommit($hash, $project = null)
	{
		$project = $project ?: $this->default_project;
		try {
			$commit = $this->api->commits($project, array(
				'commit' => $hash,
			));

			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf( 'Commit %s: %s... %s',
				$hash, substr( $commit->commit->message, 0, 100), $commit->url)
			);
		}
		catch (Exception $e) {
			$this->doPrivmsg($this->event->getSource(), "Isuck".strlen($html)."Sorry, could not find changeset {$hash}.");
		}
	}

	/**
	 * Print information and link to the latest commit for a project
	 *
	 * @param String $project optional project in the form (user|org)/repo
	 */
	public function onDoRev($project = null) {
		$project = $project ?: $this->default_project;

		try {
			// it's a single-element array, grab the first item
			$commit = current($this->api->commits($project, array(
				'per_page' => 1,
				'continue' => false,
			)));

			$commit_hash = substr( $commit->sha, 0, 8 ); // 8 characters should be safe, no?

			$commit_datetime = new DateTime($commit->commit->committer->date);
			$commit_date = $commit_datetime->format( 'j F Y' );

			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf( 'Latest Commit: %s: %s... (%s) %s',
					$commit_hash,
					substr( $commit->commit->message, 0, 100 ),
					$commit_date, $commit->url
				)
			);
		}
		catch (Exception $e) {
			$this->doPrivmsg($this->event->getSource(), "Something went wrong with that, sorry.");
		}
	}
}

/**
 * Github API
 *
 * See the documentation at http://developer.github.com/v3/
 *
 * This class is not complete, it only supports what it needs to.
 *
 * @todo Add error checking
 * @todo DRY more
 */
class GithubAPI
{
	/**
	 * Endpoint for the Github API
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * Set the API endpoint
	 */
	function __construct($api_url)
	{
		$this->api_url = $api_url;
	}

	/**
	 * Retrieve commits for a project from Github
	 *
	 * @param String $project The project to query
	 * @param Array $options Parameters by which to filter the commits
	 *
	 * @return Array The returned commits
	 */
	public function commits($project, $options = array())
	{
		$url = $this->api_url."/repos/{$project}/commits";

		// Check if we're retrieving a single commit
		if (array_key_exists('commit', $options)) {
			$url = $this->api_url."/repos/{$project}/commits/{$options['commit']}";
			$commit = $this->call($url);

			return $commit->data;
		}

		if (array_key_exists('since', $options)) {
			$options['since'] = $options['since']->format('c');
		}
		$params = http_build_query($options);
		$first_page = true;
		$commits = array();
		$page = $this->call($url.'?'.$params);
		// Should we get subsequent pages if they exist?
		$continue = true;
		if (array_key_exists('continue', $options)) {
			$continue = $options['continue'];
			unset($options['continue']);
		}
		while ($continue && isset($page->next)) {
			// If we're not on the first page, throw out the first, duplicate, commit
			if (!$first_page) {
				array_shift($page->data);
			}
			$first_page = false;
			$commits = array_merge($commits, $page->data);
			$page = $this->call($page->next);
		}
		$commits = array_merge($commits, $page->data);

		return $commits;
	}

	/**
	 * Retrieve issues for a project from Github
	 *
	 * @param String $project The project to query
	 * @param Array $options Parameters by which to filter the issues
	 *
	 * @return Array The returned issues
	 */
	public function issues($project, $options = array())
	{
		$url = $this->api_url."/repos/{$project}/issues";

		// Check if we're retrieving a single issue
		if (array_key_exists('issue', $options)) {
			$url = $this->api_url."/repos/{$project}/issues/{$options['issue']}";
			$issue = $this->call($url);

			return $issue->data;
		}

		if (array_key_exists('since', $options)) {
			$options['since'] = $options['since']->format('c');
		}
		$params = http_build_query($options);
		$issues = array();
		$page = $this->call($url.'?'.$params);
		// Should we get subsequent pages if they exist?
		$continue = true;
		if (array_key_exists('continue', $options)) {
			$continue = $options['continue'];
			unset($options['continue']);
		}
		while ($continue && isset($page->next)) {
			$issues = array_merge($issues, $page->data);
			$page = $this->call($page->next);
		}
		$issues = array_merge($issues, $page->data);

		return $issues;
	}

	/**
	 * Retrieve milestones for a project from Github
	 *
	 * @param String $project The project to query
	 * @param Array $options Parameters by which to filter the milestones
	 *
	 * @return Array The returned milestones
	 */
	public function milestones($project, $options = array())
	{
		$url = $this->api_url."/repos/{$project}/milestones";

		// Check if we're retrieving a single milestone
		if (array_key_exists('milestone', $options)) {
			$url = $this->api_url."/repos/{$project}/milestones/{$options['milestone']}";
			$milestone = $this->call($url);

			return $milestone->data;
		}

		$params = http_build_query($options);
		$milestones = $this->call($url.'?'.$params);

		return $milestones->data;
	}


	protected function call($url) {
		$handler = curl_init();
		$options = array(
			CURLOPT_URL => $url,
			CURLOPT_HEADER => true,
			CURLOPT_RETURNTRANSFER => true,
		);
		curl_setopt_array($handler, $options);

		$response = curl_exec($handler);

		$header_size = curl_getinfo($handler, CURLINFO_HEADER_SIZE);
		$headers = $this->http_parse_headers(substr($response, 0, $header_size));
		$body = substr($response, $header_size);
		curl_close($handler);

		$result = new stdClass();
		$result->data = json_decode($body);

		// Get the paging information from the headers if it's there
		// Link: <https://api.github.com/repos/habari/habari/issues?page=2&since=2012-01-01T22%3A02%3A44%2B00%3A00&state=closed>; rel="next", <https://api.github.com/repos/habari/habari/issues?page=4&since=2012-01-01T22%3A02%3A44%2B00%3A00&state=closed>; rel="last"

		if (array_key_exists('Link', $headers)) {
			$segments = explode(',', $headers['Link']);
			foreach ($segments as $segment) {
				preg_match('/<([^>]+)>.*rel="([^"]+)"/', $segment, $match);
				$result->$match[2] = $match[1];
			}
		}

		return $result;
	}

	/**
	 * Parse HTTP headers from a string. Stolen from 
	 * http://php.net/manual/en/function.http-parse-headers.php so we don't have 
	 * to rely on a pecl extension
	 *
	 * @param string $header_string The raw headers
	 * @return array An associative array of headers
	 */
	protected function http_parse_headers($header_string) {
		$headers = array();
		$fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header_string));
		foreach( $fields as $field ) {
			if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
				$match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
				if (isset($headers[$match[1]])) {
					$headers[$match[1]] = array($headers[$match[1]], $match[2]);
				} else {
					$headers[$match[1]] = trim($match[2]);
				}
			}
		}
		return $headers;
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
