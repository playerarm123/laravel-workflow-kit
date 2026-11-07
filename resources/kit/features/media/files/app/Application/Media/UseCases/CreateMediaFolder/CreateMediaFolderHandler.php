<?php

namespace App\Application\Media\UseCases\CreateMediaFolder;

use App\Application\Audit\AuditLog;
use App\Application\Media\MediaFolderNameTakenException;
use App\Domain\Media\MediaFolder\MediaFolderEntity;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use App\Domain\Shared\Ports\IdGenerator;
use Illuminate\Support\Facades\DB;

/**
 * A user adds a folder to the library, under a name no other folder has.
 */
final class CreateMediaFolderHandler
{
    public function __construct(
        protected IdGenerator $ids,
        protected MediaFolderRepository $repo,
        protected AuditLog $audit,
    ) {}

    /**
     * @throws MediaFolderNameTakenException
     */
    public function __invoke(CreateMediaFolderCommand $command): string
    {
        if ($this->repo->nameTaken($command->name)) {
            throw MediaFolderNameTakenException::taken();
        }

        $folder = MediaFolderEntity::create($this->ids->next(), $command->name);

        return DB::transaction(function () use ($folder): string {
            $this->repo->save($folder);
            $this->audit->record('media_folder.created', $folder, []);

            return $folder->id();
        });
    }
}
