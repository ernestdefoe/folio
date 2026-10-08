<?php

use Illuminate\Database\Schema\Builder;

/*
 * Members may export; guests may not until an admin says so. It is a row in
 * the Permissions grid (per tag, with flarum/tags), so either is one click.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $exists = $db->table('group_permission')
            ->where('group_id', 3)
            ->where('permission', 'discussion.folioExport')
            ->exists();

        if (! $exists) {
            $db->table('group_permission')->insert([
                'group_id' => 3,
                'permission' => 'discussion.folioExport',
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()->table('group_permission')
            ->where('permission', 'discussion.folioExport')
            ->delete();
    },
];
