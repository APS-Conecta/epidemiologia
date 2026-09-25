<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * In-memory IAppData — a hand-written fake, not a createMock, because the
 * contract the tests depend on cannot be expressed as stubs: getFile() throws
 * the REAL OCP\Files\NotFoundException (the route the app ships catches it),
 * newFile() writes, and every write is recorded in $written so a
 * never-refetch test asserts on ACTIONS ("no second document request
 * happened"), never on collaborator call counts (ADR-0007).
 *
 * Implements BOTH IAppData and ISimpleFolder: the root IS the folder the app
 * uses (IAppData extends ISimpleRoot, which is ISimpleFolder's SIBLING, not
 * its parent — so the implements clause carries both or `return $this` is a
 * TypeError). Two deliberate divergences from the real thing, named so no
 * future test leans on them in error: this fake is SINGLE-FOLDER (the app
 * uses exactly one, 'informes' — getDirectoryListing answers with it, and
 * asking for any other name throws), and newFolder() is a gate, not a
 * creator (creating a second folder would break the single-store reasoning
 * every document test rests on). The one deliberate NON-answer is
 * $withFolder=false: it models the fresh install before the first keep —
 * getFolder('informes') throws the real NotFoundException until
 * newFolder('informes') creates it, which is the branch the reader's
 * folder() exists to walk. Default true: every other test and all four
 * contract pins keep the always-there folder.
 *
 * Streams (read()/write()) are unused by this app; they answer null rather
 * than fatal a test that never asks for them.
 */
class FakeAppData implements IAppData, ISimpleFolder {
	/**
	 * The one deliberate NON-answer: false models the fresh install before
	 * the first keep — getFolder('informes') throws the real NotFoundException
	 * until newFolder('informes') creates it, which is the branch the
	 * reader's folder() exists to walk. Default true: every other test and
	 * all four contract pins keep the always-there folder.
	 */
	public bool $withFolder = true;

	/** @var array<string, string> file name => bytes */
	public array $files = [];

	/** @var list<string> every write, in order — the never-refetch ledger */
	public array $written = [];

	public function getDirectoryListing(): array {
		return [$this];
	}

	public function fileExists(string $name): bool {
		return isset($this->files[$name]);
	}

	public function getFile(string $name): ISimpleFile {
		if (!isset($this->files[$name])) {
			throw new NotFoundException("'$name' does not exist in the fake app data");
		}

		return new FakeSimpleFile($name, $this);
	}

	public function newFile(string $name, $content = null): ISimpleFile {
		$this->files[$name] = (string)($content ?? '');
		$this->written[] = $name;

		return new FakeSimpleFile($name, $this);
	}

	public function delete(): void {
		$this->files = [];
	}

	public function getName(): string {
		return 'informes';
	}

	public function getFolder(string $name): ISimpleFolder {
		if ($name !== 'informes') {
			throw new NotFoundException("no folder '$name' in the fake app data");
		}

		if (!$this->withFolder) {
			throw new NotFoundException("'informes' does not exist yet in the fake app data");
		}

		return $this;
	}

	public function newFolder(string $path): ISimpleFolder {
		if ($path === 'informes' && !$this->withFolder) {
			$this->withFolder = true;   // created, exactly once — the real thing's semantics

			return $this;
		}

		return $this->getFolder($path);
	}
}

/** The file-handle half of the fake: writes go through to the shared map. */
class FakeSimpleFile implements ISimpleFile {
	public function __construct(
		private readonly string $name,
		private readonly FakeAppData $store,
	) {
	}

	public function getName(): string {
		return $this->name;
	}

	public function getContent(): string {
		return $this->store->files[$this->name] ?? '';
	}

	public function putContent($data): void {
		$this->store->files[$this->name] = (string)$data;
		$this->store->written[] = $this->name;
	}

	public function getSize(): int|float {
		return strlen($this->store->files[$this->name] ?? '');
	}

	public function getETag(): string {
		return '';
	}

	public function getMTime(): int {
		return 0;
	}

	public function delete(): void {
		unset($this->store->files[$this->name]);
	}

	public function getMimeType(): string {
		return 'application/pdf';
	}

	public function getExtension(): string {
		return 'pdf';
	}

	public function read() {
		return null;
	}

	public function write() {
		return null;
	}
}
