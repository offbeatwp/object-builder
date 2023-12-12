<?php

namespace Builders;

use Exceptions\TermSaveException;
use WP_Error;
use WP_Term;

final class TermBuilder
{
    private int $id;
    private int $clonedFromId = 0;
    /** @var array{name?: string, taxonomy?: string, alias_of?: string, description?: string, parent?: int, slug?: string} */
    private array $args;

    /** @param WP_Term|mixed[]|null $sourceTerm */
    private function __construct(int $id, $sourceTerm)
    {
        $this->id = $id;

        if (is_array($sourceTerm)) {
            $this->args = $sourceTerm;
        } else {
            $vars = get_object_vars($sourceTerm);
            $this->clonedFromId = $vars['term_id'];
            unset($vars['term_id']);
            $this->args = $vars;
        }
    }

    /**
     * @pure
     * @param string $termName The term name. Must not exceed 200 characters.
     * @param string $taxonomy The taxonomy to which to add the term.
     */
    public static function insert(string $termName, string $taxonomy): TermBuilder
    {
        return new TermBuilder(0, ['name' => $termName, 'taxonomy' => $taxonomy]);
    }

    /**
     * @pure
     * @param positive-int $termId The ID of the term.
     * @param string $taxonomy The taxonomy of the term.
     */
    public static function update(int $termId, string $taxonomy): TermBuilder
    {
        return new TermBuilder($termId, ['taxonomy' => $taxonomy]);
    }

    /** @pure */
    public static function copy(WP_Term $term): TermBuilder
    {
        return new TermBuilder(0, $term);
    }

    /** The term description. Default empty string. */
    public function description(string $description)
    {
        $this->args['description'] = $description;
        return $this;
    }

    /**
     * The ID of the parent term.
     * @param int<0, max> $parent
     */
    public function parent(int $parent)
    {
        $this->args['parent'] = $parent;
        return $this;
    }

    /** The term slug to use. Default empty string. */
    public function slug(string $slug)
    {
        $this->args['slug'] = $slug;
        return $this;
    }

    /** Slug of the term to make this term an alias of. Default empty string. Accepts a term slug. */
    public function aliasOf(string $aliasOf)
    {
        $this->args['alias_of'] = $aliasOf;
        return $this;
    }

    /** The term name. Must not exceed 200 characters. */
    public function name(string $name)
    {
        $this->args['name'] = $name;
        return $this;
    }

    /**
     * Inserts or updates the term in the database.<br>
     * Returns term ID on success, throws TermSaveException on failure.
     * @return positive-int
     * @throws TermSaveException
     */
    public function save(): int
    {
        // Either insert or update the term
        if ($this->id && !$this->clonedFromId) {
            $result = wp_update_term($this->id, $this->args['taxonomy'], $this->args);
        } else {
            $result = wp_insert_term($this->args['name'], $this->args['taxonomy'], $this->args);
        }

        if ($result instanceof WP_Error) {
            throw new TermSaveException($result->get_error_message());
        }

        // If this is a clone, copy the post relations and term meta too
        if ($this->clonedFromId) {
            global $wpdb;

            $wpdb->query(
                $wpdb->prepare("INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) SELECT object_id, %d, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d;",
                $this->id,
                $this->clonedFromId
            ));

            $wpdb->query(
                $wpdb->prepare("INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value) SELECT %d, meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d;",
                $this->id,
                $this->clonedFromId
            ));
        }

        return $result['term_id'];
    }
}