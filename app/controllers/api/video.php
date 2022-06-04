<?php

use Appwrite\Auth\Auth;
use Appwrite\ClamAV\Network;
use Appwrite\Event\Audit;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Transcoding;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Stats\Stats;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Appwrite\Extend\Exception;
use Utopia\Image\Image;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Swoole\Request;
use Streaming\Representation;


App::post('/v1/video/buckets/:bucketId/files/:fileId')
    ->alias('/v1/video/files', ['bucketId' => 'default'])
    ->desc('Start transcoding video')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
//    ->label('event', 'buckets.[bucketId].files.[fileId].create')
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'File ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->inject('mode')
    ->inject('deviceFiles')
    ->inject('deviceLocal')
    ->action(action: function (string $bucketId, string $fileId, ?array $read, ?array $write, Request $request, Response $response, Database $dbForProject, $project, Document $user, Audit $audits, Stats $usage, Event $events, string $mode, Device $deviceFiles, Device $deviceLocal) {
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty()
            || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception('Bucket not found', 404, Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        // Check bucket permissions when enforced
        $permissionBucket = $bucket->getAttribute('permission') === 'bucket';
        if ($permissionBucket) {
            $validator = new Authorization('write');
            if (!$validator->isValid($bucket->getWrite())) {
                throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
            }
        }

        $read = (is_null($read) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $read ?? []; // By default set read permissions for user
        $write = (is_null($write) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $write ?? [];

        // Users can only add their roles to files, API keys and Admin users can add any
        $roles = Authorization::getRoles();

        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles)) {
            foreach ($read as $role) {
                if (!Authorization::isRole($role)) {
                    throw new Exception('Read permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
                }
            }
            foreach ($write as $role) {
                if (!Authorization::isRole($role)) {
                    throw new Exception('Write permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
                }
            }
        }

        $transcoder = new Transcoding();
        $transcoder
            ->setUser($user)
            ->setProject($project)
            ->setBucketId($bucketId)
            ->setFileId($fileId)
            ->trigger();

        $response->json(['result' => 'ok']);
    });


App::get('/v1/video/buckets/:bucketId/files/:fileId/renditions')
    ->alias('/v1/storage/files/:fileId/renditions', ['bucketId' => 'default'])
    ->desc('Get File renditions')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFile')
    ->label('sdk.description', '/docs/references/storage/get-file.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FILE)
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('mode')
    ->action(function (string $bucketId, string $fileId, Response $response, Database $dbForProject, Stats $usage, string $mode) {

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if (
            $bucket->isEmpty()
            || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)
        ) {
            throw new Exception('Bucket not found', 404, Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        // Check bucket permissions when enforced
        if ($bucket->getAttribute('permission') === 'bucket') {
            $validator = new Authorization('read');
            if (!$validator->isValid($bucket->getRead())) {
                throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
            }
        }

        if ($bucket->getAttribute('permission') === 'bucket') {
            $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
        } else {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
        }

        if ($file->isEmpty() || $file->getAttribute('bucketId') !== $bucketId) {
            throw new Exception('File not found', 404, Exception::STORAGE_FILE_NOT_FOUND);
        }

        $queries = [
            new Query('bucketId', Query::TYPE_EQUAL, [$bucketId]),
            new Query('fileId', Query::TYPE_EQUAL, [$fileId])
        ];

        $renditions = Authorization::skip(fn () => $dbForProject->find('bucket_' . $bucket->getInternalId(). '_video_renditions', $queries, 12, 0, [], ['ASC']));
        $stream = 'Not available';
        foreach ($renditions as $rendition) {
                if('ready' === $rendition->getAttribute('status')){
                    $stream = 'http://localhost/videos/' . $fileId . 'm3u8';
                    break;
                }
         }

        $response->json([
            'stream' => $stream,
            'renditions' => $renditions
        ]);

//        $response->dynamic(new Document([
//            'total' => $dbForProject->count('bucket_' . $bucket->getInternalId(). '_video_renditions', $queries, APP_LIMIT_COUNT),
//            'renditions' => $renditions,
//        ]), Response::MODEL_FILE_RENDITIONS_LIST);

    });
