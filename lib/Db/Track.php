<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2016 - 2023
 */

namespace OCA\Music\Db;

use OCA\Music\Utility\Util;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?int getNumber()
 * @method void setNumber(?int $number)
 * @method ?int getDisk()
 * @method void setDisk(?int $disk)
 * @method ?int getYear()
 * @method void setYear(?int $year)
 * @method int getArtistId()
 * @method void setArtistId(int $artistId)
 * @method ?string getArtistName()
 * @method void setArtistName(?string $artistName)
 * @method int getAlbumId()
 * @method void setAlbumId(int $albumId)
 * @method ?string getAlbumName()
 * @method void setAlbumName(?string $albumName)
 * @method ?Album getAlbum()
 * @method void setAlbum(?Album $album)
 * @method ?int getLength()
 * @method void setLength(?int $length)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method ?int getBitrate()
 * @method void setBitrate(?int $bitrate)
 * @method string getMimetype()
 * @method void setMimetype(string $mimetype)
 * @method ?string getMbid()
 * @method void setMbid(?string $mbid)
 * @method ?string getStarred()
 * @method void setStarred(?string $timestamp)
 * @method ?int getGenreId()
 * @method void setGenreId(?int $genreId)
 * @method ?string getGenreName()
 * @method void setGenreName(?string $genreName)
 * @method string getFilename()
 * @method void setFilename(string $filename)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method int getFileModTime()
 * @method void setFileModTime(int $secsFromEpoch)
 * @method ?int getNumberOnPlaylist()
 * @method void setNumberOnPlaylist(?int $number)
 * @method int getPlayCount()
 * @method void setPlayCount(int $count)
 * @method ?string getLastPlayed()
 * @method void setLastPlayed(?string $timestamp)
 */
class Track extends Entity {
	public $title;
	public $number;
	public $disk;
	public $year;
	public $artistId;
	public $albumId;
	public $length;
	public $fileId;
	public $bitrate;
	public $uri;
	public $mimetype;
	public $mbid;
	public $starred;
	public $genreId;
	public $playCount;
	public $lastPlayed;

	// not from the music_tracks table but still part of the standard content of this entity:
	public $filename;
	public $size;
	public $fileModTime;
	public $albumName;
	public $artistName;
	public $genreName;

	// the rest of the variables are injected separately when needed
	public $album;
	public $numberOnPlaylist;

	public function __construct() {
		$this->addType('number', 'int');
		$this->addType('disk', 'int');
		$this->addType('year', 'int');
		$this->addType('artistId', 'int');
		$this->addType('albumId', 'int');
		$this->addType('length', 'int');
		$this->addType('bitrate', 'int');
		$this->addType('fileId', 'int');
		$this->addType('genreId', 'int');
		$this->addType('size', 'int');
		$this->addType('fileModTime', 'int');
		$this->addType('playCount', 'int');
	}

	public function getUri(IURLGenerator $urlGenerator) : string {
		return $urlGenerator->linkToRoute(
			'music.shivaApi.track',
			['trackId' => $this->id]
		);
	}

	public function getArtistWithUri(IURLGenerator $urlGenerator) : array {
		return [
			'id' => $this->artistId,
			'uri' => $urlGenerator->linkToRoute(
				'music.shivaApi.artist',
				['artistId' => $this->artistId]
			)
		];
	}

	public function getAlbumWithUri(IURLGenerator $urlGenerator) : array {
		return [
			'id' => $this->albumId,
			'uri' => $urlGenerator->linkToRoute(
				'music.shivaApi.album',
				['albumId' => $this->albumId]
			)
		];
	}

	public function getArtistNameString(IL10N $l10n) : string {
		return $this->getArtistName() ?: Artist::unknownNameString($l10n);
	}

	public function getAlbumNameString(IL10N $l10n) : string {
		return $this->getAlbumName() ?: Album::unknownNameString($l10n);
	}

	public function getGenreNameString(IL10N $l10n) : string {
		return $this->getGenreName() ?: Genre::unknownNameString($l10n);
	}

	public function toCollection() : array {
		return [
			'title' => $this->getTitle(),
			'number' => $this->getNumber(),
			'disk' => $this->getDisk(),
			'artistId' => $this->getArtistId(),
			'length' => $this->getLength(),
			'files' => [$this->getMimetype() => $this->getFileId()],
			'id' => $this->getId(),
		];
	}

	public function toAPI(IURLGenerator $urlGenerator) : array {
		return [
			'title' => $this->getTitle(),
			'ordinal' => $this->getAdjustedTrackNumber(),
			'artist' => $this->getArtistWithUri($urlGenerator),
			'album' => $this->getAlbumWithUri($urlGenerator),
			'length' => $this->getLength(),
			'files' => [$this->getMimetype() => $urlGenerator->linkToRoute(
				'music.api.download',
				['fileId' => $this->getFileId()]
			)],
			'bitrate' => $this->getBitrate(),
			'id' => $this->getId(),
			'slug' => $this->slugify('title'),
			'uri' => $this->getUri($urlGenerator)
		];
	}

	public function toAmpacheApi(IL10N $l10n, callable $createPlayUrl, callable $createImageUrl, string $genreKey, bool $includeArtists) : array {
		$album = $this->getAlbum();

		$result = [
			'id' => (string)$this->getId(),
			'title' => $this->getTitle() ?: '',
			'name' => $this->getTitle() ?: '',
			'artist' => [
				'id' => (string)$this->getArtistId() ?: '0',
				'value' => $this->getArtistNameString($l10n)
			],
			'albumartist' => [
				'id' => (string)$album->getAlbumArtistId() ?: '0',
				'value' => $album->getAlbumArtistNameString($l10n)
			],
			'album' => [
				'id' => (string)$album->getId() ?: '0',
				'value' => $album->getNameString($l10n)
			],
			'url' => $createPlayUrl($this),
			'time' => $this->getLength(),
			'year' => $this->getYear(),
			'track' => $this->getAdjustedTrackNumber(), // TODO: maybe there should be a user setting to select plain or adjusted number
			'playlisttrack' => $this->getAdjustedTrackNumber(),
			'disk' => $this->getDisk(),
			'filename' => $this->getFilename(),
			'format' => $this->getFileExtension(),
			'stream_format' => $this->getFileExtension(),
			'bitrate' => $this->getBitrate(),
			'stream_bitrate' => $this->getBitrate(),
			'mime' => $this->getMimetype(),
			'stream_mime' => $this->getMimetype(),
			'size' => $this->getSize(),
			'art' => $createImageUrl($this),
			'rating' => 0,
			'preciserating' => 0,
			'playcount' => $this->getPlayCount(),
			'flag' => !empty($this->getStarred()),
			'language' => null,
			'lyrics' => null,
			'mode' => null, // cbr/vbr
			'rate' => null, // sample rate [Hz]
			'replaygain_album_gain' => null,
			'replaygain_album_peak' => null,
			'replaygain_track_gain' => null,
			'replaygain_track_peak' => null,
			'r128_album_gain' => null,
			'r128_track_gain' => null,
		];

		$genreId = $this->getGenreId();
		if ($genreId !== null) {
			$result[$genreKey] = [[
				'id' => (string)$genreId,
				'value' => $this->getGenreNameString($l10n),
				'count' => 1
			]];
		}

		if ($includeArtists) {
			// Add another property `artists`. Apparently, it exists to support mulitple artists per song
			// but we don't have such possibility and this is always just a 1-item array.
			$result['artists'] = [$result['artist']];
		}
	
		return $result;
	}

	/**
	 * The same API format is used both on "old" and "new" API methods. The "new" API adds some
	 * new fields for the songs, but providing some extra fields shouldn't be a problem for the
	 * older clients. The $track entity must have the Album reference injected prior to calling this.
	 */
	public function toSubsonicApi(IL10N $l10n) : array {
		$albumId = $this->getAlbumId();
		$album = $this->getAlbum();
		$hasCoverArt = ($album !== null && !empty($album->getCoverFileId()));

		return [
			'id' => 'track-' . $this->getId(),
			'parent' => 'album-' . $albumId,
			'discNumber' => $this->getDisk(),
			'title' => $this->getTitle(),
			'artist' => $this->getArtistNameString($l10n),
			'isDir' => false,
			'album' => $this->getAlbumNameString($l10n),
			'year' => $this->getYear(),
			'size' => $this->getSize(),
			'contentType' => $this->getMimetype(),
			'suffix' => $this->getFileExtension(),
			'duration' => $this->getLength() ?? 0,
			'bitRate' => empty($this->getBitrate()) ? null : (int)\round($this->getBitrate()/1000), // convert bps to kbps
			//'path' => '',
			'isVideo' => false,
			'albumId' => 'album-' . $albumId,
			'artistId' => 'artist-' . $this->getArtistId(),
			'type' => 'music',
			'created' => Util::formatZuluDateTime($this->getCreated()),
			'track' => $this->getAdjustedTrackNumber(false), // DSub would get confused of playlist numbering, https://github.com/owncloud/music/issues/994
			'starred' => Util::formatZuluDateTime($this->getStarred()),
			'genre' => empty($this->getGenreId()) ? null : $this->getGenreNameString($l10n),
			'coverArt' => !$hasCoverArt ? null : 'album-' . $albumId
		];
	}

	public function getAdjustedTrackNumber(bool $enablePlaylistNumbering=true) : ?int {
		// Unless disabled, the number on playlist overrides the track number if it is set.
		if ($enablePlaylistNumbering && $this->numberOnPlaylist !== null) {
			$trackNumber = $this->numberOnPlaylist;
		} else {
			// On single-disk albums, the track number is given as-is.
			// On multi-disk albums, the disk-number is applied to the track number.
			// In case we have no Album reference, the best we can do is to apply the
			// disk number if it is greater than 1. For disk 1, we don't know if this
			// is a multi-disk album or not.
			$numberOfDisks = ($this->album) ? $this->album->getNumberOfDisks() : null;
			$trackNumber = $this->getNumber();

			if ($this->disk > 1 || $numberOfDisks > 1) {
				$trackNumber = $trackNumber ?: 0;
				$trackNumber += (100 * $this->disk);
			}
		}

		return $trackNumber;
	}

	public function getFileExtension() : string {
		$parts = \explode('.', $this->getFilename());
		return \end($parts);
	}

}
