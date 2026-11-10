<?php

namespace App\Support;

/**
 * Makes an encode the kernel's first choice when memory runs out.
 *
 * The OOM killer picks the process with the highest badness, which is mostly its size — so before
 * this the victim was a coin toss between an ffmpeg, a Horizon worker and RustFS, and losing either
 * of the last two takes the node (or the chunk store every node reads) down with it. Raising one's
 * own `oom_score_adj` needs no privilege, and the value survives `exec`, so the shell sets it and
 * then becomes ffmpeg. A killed ffmpeg is one window retried; a killed worker is every job on it.
 *
 * Chunks score highest: one is the cheapest thing on the node to lose. Probes score half that, since
 * losing one stalls a whole video's preparation rather than one window of it.
 */
class OomScore
{
    public static function chunk(): int
    {
        return max(0, min(1000, (int) config('nuke.video.chunk_oom_score_adj', 1000)));
    }

    /** Follows the chunks' value, so CHUNK_OOM_SCORE_ADJ=0 switches both off. */
    public static function probe(): int
    {
        return intdiv(self::chunk(), 2);
    }

    /**
     * The command behind a tiny `sh -c` that raises its own score and then execs it, or the command
     * unchanged when the score is 0.
     *
     * Shaped to survive Symfony prefixing every string command with `exec ` (a plain
     * `echo …; exec ffmpeg …` would exec the echo and never reach ffmpeg): the command's own words
     * follow the script as `$0 "$@"`, so the shell parses them exactly as it parsed the bare
     * command — quotes included. Both shells exec, so ffmpeg keeps the pid Symfony watches and a
     * SIGKILL still reads as exit 137. A /proc that refuses the write costs only the score.
     */
    public static function wrap(string $command, int $score): string
    {
        if ($score <= 0) {
            return $command;
        }

        return "sh -c 'echo {$score} >/proc/self/oom_score_adj 2>/dev/null; exec \"\$0\" \"\$@\"' {$command}";
    }

    /**
     * SIGKILL, by the 128+signal convention. Our own timeout surfaces as ProcessTimedOutException
     * and a deploy's stop as SIGTERM (received signal 15), so on an encode this is the kernel.
     */
    public static function killed(?int $exitCode): bool
    {
        return $exitCode === 137;
    }
}
