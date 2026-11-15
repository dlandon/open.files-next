#!/usr/bin/php
<?PHP
/* Copyright 2015-2026, Dan Landon.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

/* Read the process identity and state from /proc/PID/stat. */
function get_process_info(int $pid): ?array {
	$stat	= @file_get_contents("/proc/$pid/stat");
	$end	= ($stat === false) ? false : strrpos($stat, ')');
	$info	= null;

	if ($end !== false) {
		/* The command name is parenthesized and can contain spaces. */
		$fields = preg_split('/\s+/', trim(substr($stat, $end + 1)));
		if (isset($fields[0], $fields[19]) && ctype_digit($fields[19])) {
			$info = [
				'state'			=> $fields[0],
				'ppid'			=> (int)$fields[1],
				'start_time'	=> $fields[19]
			];
		}
	}

	return $info;
}

/* Distinguish a running process, an exited process, and a reused PID. */
function process_status(int $pid, string $start_time): string {
	$info	= get_process_info($pid);
	$status	= 'exited';

	if ($info !== null) {
		if ($info['start_time'] !== $start_time) {
			$status = 'changed';
		} elseif ($pid === 2 || $info['ppid'] === 2) {
			$status = 'protected';
		} elseif (!in_array($info['state'], ['Z', 'X', 'x'], true)) {
			$status = 'running';
		}
	}

	return $status;
}

/* Wait briefly for a signal to take effect, checking identity throughout. */
function wait_for_process(int $pid, string $start_time, int $seconds): string {
	$status = process_status($pid, $start_time);

	for ($i = 0; $i < $seconds * 5 && $status === 'running'; $i++) {
		usleep(200000);
		$status = process_status($pid, $start_time);
	}

	return $status;
}

function flush_all(): void {
	if (ob_get_contents() !== false) {
		ob_flush();
	}
	flush();
}

/* Require a valid PID and the process start time captured by Open Files. */
$pid_arg = $argv[1] ?? '';
$start_arg = $argv[2] ?? '';
$valid_pid = ctype_digit((string)$pid_arg) && (int)$pid_arg > 1;
$valid_start = ctype_digit((string)$start_arg) && $start_arg !== '';

if (!$valid_pid || !$valid_start) {
	echo "Invalid process ID or missing process identity.\n";
} else {
	$pnum = (int)$pid_arg;
	$start_time = (string)$start_arg;
	$status = process_status($pnum, $start_time);

	if ($status !== 'running') {
		if ($status === 'protected') {
			echo "Process $pnum is a protected system process; no signal sent.\n";
		} else {
			echo "Process $pnum has ended or changed; no signal sent.\n";
		}
	} else {
		echo "Ending process $pnum...\n";
		flush_all();

		/* Preserve SIGINT as the initial, gentle termination request. */
		foreach (['INT' => 3, 'TERM' => 3, 'KILL' => 1] as $signal => $delay) {
			$status = process_status($pnum, $start_time);
			if ($status !== 'running') {
				break;
			}

			echo "Sending SIG$signal...\n";
			flush_all();
			shell_exec("kill -$signal $pnum 2>/dev/null");
			$status = wait_for_process($pnum, $start_time, $delay);
		}

		if ($status === 'exited') {
			echo "Process $pnum terminated successfully.\n";
		} elseif ($status === 'changed') {
			echo "Process $pnum identity changed; termination stopped.\n";
		} elseif ($status === 'protected') {
			echo "Process $pnum is protected; termination stopped.\n";
		} else {
			echo "Process $pnum is still running; termination failed.\n";
		}
		flush_all();
	}
}
?>
