<?php

namespace Aerys;

use Amp\{ Promise, Success, function any };

class Bootstrapper {
    private $hostAggregator;

    public function __construct(callable $hostAggregator = null) {
        $this->hostAggregator = $hostAggregator ?: ["\\Aerys\\Host", "getDefinitions"];
    }

    /**
     * Bootstrap a server from command line options
     *
     * @param \Aerys\Logger $logger
     * @param \Aerys\Console $console
     * @return \Generator
     */
    public function boot(Logger $logger, Console $console): \Generator {
        $configFile = self::selectConfigFile((string) $console->getArg("config"));
        $logger->info("Using config file found at $configFile");

        // may return Promise or Generator for async I/O inside config file
        $returnValue = include $configFile;

        if (!$returnValue) {
            throw new \DomainException(
                "Config file inclusion failure: {$configFile}"
            );
        }

        if (is_callable($returnValue)) {
            $returnValue = \call_user_func($returnValue);
        }

        if ($returnValue instanceof \Generator) {
            yield from $returnValue;
        } elseif ($returnValue instanceof Promise) {
            yield $returnValue;
        }

        if (!defined("AERYS_OPTIONS")) {
            $options = [];
        } elseif (is_array(AERYS_OPTIONS)) {
            $options = AERYS_OPTIONS;
        } else {
            throw new \DomainException(
                "Invalid AERYS_OPTIONS constant: array expected, got " . gettype(AERYS_OPTIONS)
            );
        }
        if ($console->isArgDefined("debug")) {
            $options["debug"] = true;
        }

        $options["configPath"] = $configFile;

        $options = $this->generateOptionsObjFromArray($options);
        $vhosts = new VhostContainer;
        $ticker = new Ticker($logger);
        $server = new Server($options, $vhosts, $logger, $ticker);

        $bootLoader = function(Bootable $bootable) use ($server, $logger) {
            $booted = $bootable->boot($server, $logger);
            if ($booted !== null && !$booted instanceof Middleware && !is_callable($booted)) {
                throw new \InvalidArgumentException("Any return value of " . get_class($bootable) . "::boot() must return an instance of Aerys\\Middleware and/or be callable, got " . gettype($booted) . ".");
            }
            return $booted ?? $bootable;
        };
        $hosts = \call_user_func($this->hostAggregator) ?: [new Host];
        foreach ($hosts as $host) {
            $vhost = $this->buildVhost($host, $bootLoader);
            $vhosts->use($vhost);
        }

        return $server;
    }

    public static function selectConfigFile(string $configFile): string {
        if ($configFile == "") {
            throw new \DomainException(
                "No config file found, specify one via the -c switch on command line"
            );
        }

        return realpath(is_dir($configFile) ? rtrim($configFile, "/") . "/config.php" : $configFile);
    }

    private function generateOptionsObjFromArray(array $optionsArray): Options {
        try {
            $optionsObj = new Options;
            foreach ($optionsArray as $key => $value) {
                $optionsObj->{$key} = $value;
            }
            return $optionsObj->debug ? $optionsObj : $this->generatePublicOptionsStruct($optionsObj);
        } catch (\Throwable $e) {
            throw new \DomainException(
                "Failed assigning options from config file", 0, $e
            );
        }
    }

    private function generatePublicOptionsStruct(Options $options): Options {
        $code = "return new class extends \\Aerys\\Options {\n";
        foreach ((new \ReflectionClass($options))->getProperties() as $property) {
            $name = $property->getName();
            if ($name[0] !== "_") {
                $code .= "\tpublic \${$name};\n";
            }
        }
        $code .= "};\n";
        $publicOptions = eval($code);
        foreach ($publicOptions as $option => $value) {
            $publicOptions->{$option} = $options->{$option};
        }

        return $publicOptions;
    }

    private function buildVhost(Host $host, callable $bootLoader): Vhost {
        try {
            $hostExport = $host->export();
            $interfaces = $hostExport["interfaces"];
            $name = $hostExport["name"];
            $actions = $hostExport["actions"];
            list($application, $middlewares) = $this->bootApplication($actions, $bootLoader);
            $vhost = new Vhost($name, $interfaces, $application, $middlewares);
            if ($crypto = $hostExport["crypto"]) {
                $vhost->setCrypto($crypto);
            }

            return $vhost;

        } catch (\Throwable $previousException) {
            throw new \DomainException(
                "Failed building Vhost instance",
                $code = 0,
                $previousException
            );
        }
    }

    private function bootApplication(array $actions, callable $bootLoader): array {
        $middlewares = [];
        $applications = [];

        foreach ($actions as $key => $action) {
            if ($action instanceof Bootable) {
                $action = $bootLoader($action);
            }
            if ($action instanceof Middleware) {
                $middlewares[] = [$action, "do"];
            } elseif (is_array($action) && $action[0] instanceof Middleware) {
                $middlewares[] = [$action[0], "do"];
            }

            if (is_callable($action)) {
                $applications[] = $action;
            }
        }

        if (empty($applications)) {
            $application = function(Request $request, Response $response) {
                $response->end("<html><body><h1>It works!</h1></body></html>");
            };

            return [$application, $middlewares];
        }

        if (count($applications) === 1) {
            $application = current($applications);

            return [$application, $middlewares];
        }

        // Observe the Server in our stateful multi-responder so if a shutdown triggers
        // while we're iterating over our coroutines we can send a 503 response. This
        // obviates the need for applications to pay attention to server state themselves.
        $application = $bootLoader(new class($applications) implements Bootable, ServerObserver {
            private $applications;
            private $isStopping = false;

            public function __construct(array $applications) {
                $this->applications = $applications;
            }

            public function boot(Server $server, Logger $logger) {
                $server->attach($this);
            }

            public function update(Server $server): Promise {
                if ($server->state() === Server::STOPPING) {
                    $this->isStopping = true;
                }

                return new Success;
            }

            public function __invoke(Request $request, Response $response) {
                foreach ($this->applications as $action) {
                    $out = $action($request, $response);
                    if ($out instanceof \Generator) {
                        yield from $out;
                    }
                    if ($response->state() & Response::STARTED) {
                        return;
                    }
                    if ($this->isStopping) {
                        $response->setStatus(HTTP_STATUS["SERVICE_UNAVAILABLE"]);
                        $response->setReason("Server shutting down");
                        $response->setHeader("Aerys-Generic-Response", "enable");
                        $response->end();
                        return;
                    }
                }
            }
        });

        return [$application, $middlewares];
    }
}
