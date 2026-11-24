<?php

namespace App\Services;

use App\Models\Node;

class DockerService
{
    public function __construct(
        private SSHService $ssh,
        private SshKeyService $sshKeys,
    ) {}

    public function run(Node $node, string $command, int $timeout = 30): string
    {
        return trim($this->ssh->run(
            ip: $node->sshHost(),
            user: $node->user,
            privateKey: $this->sshKeys->privateKey(),
            command: "docker {$command}",
            timeout: $timeout,
        ));
    }

    public function removeContainer(Node $node, string $name): void
    {
        $this->run($node, "rm -f {$name}");
    }

    public function removeVolume(Node $node, string $name): void
    {
        // A volume that never existed is not an error worth failing a delete over.
        $this->run($node, "volume rm -f {$name} 2>/dev/null || true");
    }

    public function listContainers(Node $node): array
    {
        $output = $this->run($node, 'ps -a --format "{{json .}}"');

        if (empty($output)) {
            return [];
        }

        $containers = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            $data = json_decode($line, true);
            if ($data) {
                $containers[] = $data;
            }
        }

        return $containers;
    }
}
