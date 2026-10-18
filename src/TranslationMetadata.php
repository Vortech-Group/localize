<?php

declare(strict_types=1);

namespace Vortech\Localize;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Vortech\Localize\Attributes\Localized;
use Vortech\Localize\Attributes\LocalizedSlug;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;

/**
 * Resolves where a model's translations live: the declared columns, the
 * translation table, the foreign key and the connection.
 *
 * Only the column list is cached (per class). The table, key and connection
 * are read from the instance every time, so a model that overrides them or
 * runs on another connection is never mixed up with another.
 *
 * @internal
 */
final class TranslationMetadata
{
    private const array RESERVED = ['id', 'locale', 'created_at', 'updated_at'];

    /** @var array<class-string, list<string>> */
    private static array $declared = [];

    /** @var array<class-string, ?LocalizedSlug> */
    private static array $slugs = [];

    /**
     * @param  list<string>  $fields
     */
    private function __construct(
        public readonly array $fields,
        public readonly string $table,
        public readonly string $foreignKey,
        public readonly string $ownerKey,
        public readonly ?string $connection,
        public readonly ?LocalizedSlug $slug = null,
    ) {}

    public static function of(Model $model): self
    {
        if (! method_exists($model, 'getTranslationTable') || ! method_exists($model, 'getTranslationForeignKey')) {
            throw InvalidTranslationDefinition::missingTrait($model::class);
        }

        $table = $model->getTranslationTable();
        $foreignKey = $model->getTranslationForeignKey();

        if (! is_string($table) || ! is_string($foreignKey)) {
            throw InvalidTranslationDefinition::invalid(sprintf('getTranslationTable() and getTranslationForeignKey() of %s must return strings.', $model::class));
        }

        $fields = self::fields($model::class);

        if (in_array($foreignKey, $fields, true)) {
            throw InvalidTranslationDefinition::reservedField($model::class, $foreignKey);
        }

        return new self(
            fields: $fields,
            table: $table,
            foreignKey: $foreignKey,
            ownerKey: $model->getKeyName(),
            connection: $model->getConnectionName(),
            slug: self::slug($model::class, $fields),
        );
    }

    /**
     * The declared columns, from the nearest class in the hierarchy that has
     * its own #[Localized] attribute. Declarations are never merged.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    public static function fields(string $class): array
    {
        return self::$declared[$class] ??= self::resolve($class);
    }

    /**
     * The slug rule from the nearest class that declares #[LocalizedSlug], if any.
     *
     * @param  class-string  $class
     * @param  list<string>  $fields
     */
    private static function slug(string $class, array $fields): ?LocalizedSlug
    {
        if (array_key_exists($class, self::$slugs)) {
            return self::$slugs[$class];
        }

        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $attributes = $reflection->getAttributes(LocalizedSlug::class);

            if ($attributes === []) {
                continue;
            }

            $slug = $attributes[0]->newInstance();

            if ($slug->from === $slug->to) {
                throw InvalidTranslationDefinition::invalidSlug($reflection->getName(), 'the source and the slug column are the same');
            }

            foreach ([$slug->from, $slug->to] as $column) {
                if (! in_array($column, $fields, true)) {
                    throw InvalidTranslationDefinition::invalidSlug($reflection->getName(), sprintf('"%s" is not declared with #[Localized]', $column));
                }
            }

            return self::$slugs[$class] = $slug;
        }

        return self::$slugs[$class] = null;
    }

    public static function flush(): void
    {
        self::$declared = [];
        self::$slugs = [];
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private static function resolve(string $class): array
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $attributes = $reflection->getAttributes(Localized::class);

            if ($attributes === []) {
                continue;
            }

            $fields = array_values(array_unique($attributes[0]->newInstance()->fields));

            if ($fields === []) {
                throw InvalidTranslationDefinition::noFields($reflection->getName());
            }

            foreach ($fields as $field) {
                if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $field) !== 1) {
                    throw InvalidTranslationDefinition::invalidField($reflection->getName(), $field);
                }

                if (in_array($field, self::RESERVED, true)) {
                    throw InvalidTranslationDefinition::reservedField($reflection->getName(), $field);
                }
            }

            return $fields;
        }

        throw InvalidTranslationDefinition::missingAttribute($class);
    }
}
