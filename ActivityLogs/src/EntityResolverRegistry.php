<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Entity display-name resolver registry for the ActivityLogs admin viewer.
 */

declare(strict_types=1);

namespace ActivityLogs;

/**
 * EntityResolverRegistry - host-supplied entity name resolvers
 *
 * The host registers a callable per entity_type that converts a raw entity_id
 * to a human-readable display name. When no resolver is registered, or when the
 * resolver throws, the fallback "{entityType} #{entityId}" is used so that a
 * missing or broken resolver never breaks the table.
 *
 * Resolver failures are logged once per entity_type (to avoid log spam) via the
 * logger callable injected from the facade.
 *
 * @package ActivityLogs
 */
class EntityResolverRegistry
{
    /** @var array<string,callable(string): mixed> Single-item resolvers */
    private array $resolvers = [];

    /** @var array<string,callable(array<string>): array<string,string>> Batch resolvers */
    private array $batchResolvers = [];

    /** @var array<string,true> Entity types whose resolver has already logged a failure */
    private array $loggedFailures = [];

    /**
     * @param callable(string, string, array): void $logger Structured logger closure
     */
    public function __construct(
        private readonly mixed $logger,
    ) {
    }

    /**
     * Register a single-item resolver for an entity type.
     *
     * The callable receives the entity_id as a string and must return a string.
     *
     * @param string   $entityType Entity type key (e.g. 'product', 'order')
     * @param callable $resolver   fn(string $id): string
     * @return void
     */
    public function register(string $entityType, callable $resolver): void
    {
        $this->resolvers[$entityType] = $resolver;
    }

    /**
     * Register a batch resolver for an entity type.
     *
     * Preferred over single-item resolver when the backing store can resolve many
     * IDs in one query. The callable receives an array of string IDs and returns a
     * map of id → display name. Missing IDs will fall back to the default.
     *
     * @param string   $entityType Entity type key
     * @param callable $resolver   fn(array<string> $ids): array<string,string>
     * @return void
     */
    public function registerBatch(string $entityType, callable $resolver): void
    {
        $this->batchResolvers[$entityType] = $resolver;
    }

    /**
     * Resolve a single entity to its display name.
     *
     * @param string $entityType Entity type
     * @param string $entityId   Entity identifier
     * @return string Display name or fallback "{entityType} #{entityId}"
     */
    public function resolve(string $entityType, string $entityId): string
    {
        if (!isset($this->resolvers[$entityType]) && !isset($this->batchResolvers[$entityType])) {
            return $this->fallback($entityType, $entityId);
        }

        try {
            if (isset($this->batchResolvers[$entityType])) {
                $map = ($this->batchResolvers[$entityType])([$entityId]);
                return isset($map[$entityId]) ? (string)$map[$entityId] : $this->fallback($entityType, $entityId);
            }
            return (string)($this->resolvers[$entityType])($entityId);
        } catch (\Throwable $e) {
            $this->logFailure($entityType, $e);
            return $this->fallback($entityType, $entityId);
        }
    }

    /**
     * Resolve a batch of entity IDs for one entity type, returning a map of id → name.
     *
     * @param string         $entityType Entity type
     * @param array<string>  $ids        Entity IDs to resolve
     * @return array<string,string> Map of id → display name (may be incomplete on failure)
     */
    public function resolveBatch(string $entityType, array $ids): array
    {
        if ($ids === [] || (!isset($this->resolvers[$entityType]) && !isset($this->batchResolvers[$entityType]))) {
            return [];
        }

        try {
            if (isset($this->batchResolvers[$entityType])) {
                $result = ($this->batchResolvers[$entityType])($ids);
                return is_array($result) ? array_map('strval', $result) : [];
            }
            // Fall back to iterating the single resolver
            $map = [];
            foreach ($ids as $id) {
                $map[$id] = (string)($this->resolvers[$entityType])($id);
            }
            return $map;
        } catch (\Throwable $e) {
            $this->logFailure($entityType, $e);
            return [];
        }
    }

    /**
     * Return the fallback display string when no resolver is found or a resolver fails.
     *
     * @param string $entityType Entity type
     * @param string $entityId   Entity identifier
     * @return string Fallback string
     */
    private function fallback(string $entityType, string $entityId): string
    {
        return $entityType . ' #' . $entityId;
    }

    /**
     * Log a resolver failure once per entity type to avoid log spam.
     *
     * @param string     $entityType Entity type whose resolver failed
     * @param \Throwable $e          The exception that was caught
     * @return void
     */
    private function logFailure(string $entityType, \Throwable $e): void
    {
        if (isset($this->loggedFailures[$entityType])) {
            return;
        }
        $this->loggedFailures[$entityType] = true;
        ($this->logger)('warning', 'ActivityLogs entity resolver failed', [
            'entity_type' => $entityType,
            'error'       => $e->getMessage(),
            'class'       => $e::class,
        ]);
    }
}
