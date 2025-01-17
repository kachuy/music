<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2016 - 2023
 */

namespace OCA\Music\BusinessLayer;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayer;
use OCA\Music\AppFramework\Core\Logger;

use OCA\Music\Db\Playlist;
use OCA\Music\Db\PlaylistMapper;
use OCA\Music\Db\SortBy;
use OCA\Music\Db\Track;
use OCA\Music\Db\TrackMapper;

use OCA\Music\Utility\Random;
use OCA\Music\Utility\Util;

/**
 * Base class functions with actually used inherited types to help IDE and Scrutinizer:
 * @method Playlist find(int $playlistId, string $userId)
 * @method Playlist[] findAll(string $userId, int $sortBy=SortBy::None, int $limit=null, int $offset=null)
 * @method Playlist[] findAllByName(string $name, string $userId, int $matchMode=MatchMode::Exact, int $limit=null, int $offset=null)
 * @phpstan-extends BusinessLayer<Playlist>
 */
class PlaylistBusinessLayer extends BusinessLayer {
	protected $mapper; // eclipse the definition from the base class, to help IDE and Scrutinizer to know the actual type
	private $trackMapper;
	private $logger;

	public function __construct(
			PlaylistMapper $playlistMapper,
			TrackMapper $trackMapper,
			Logger $logger) {
		parent::__construct($playlistMapper);
		$this->mapper = $playlistMapper;
		$this->trackMapper = $trackMapper;
		$this->logger = $logger;
	}

	public function setTracks(array $trackIds, int $playlistId, string $userId) : Playlist {
		$playlist = $this->find($playlistId, $userId);
		$playlist->setTrackIdsFromArray($trackIds);
		$this->mapper->update($playlist);
		return $playlist;
	}

	public function addTracks(array $trackIds, int $playlistId, string $userId) : Playlist {
		$playlist = $this->find($playlistId, $userId);
		$prevTrackIds = $playlist->getTrackIdsAsArray();
		$playlist->setTrackIdsFromArray(\array_merge($prevTrackIds, $trackIds));
		$this->mapper->update($playlist);
		return $playlist;
	}

	public function removeTracks(array $trackIndices, int $playlistId, string $userId) : Playlist {
		$playlist = $this->find($playlistId, $userId);
		$trackIds = $playlist->getTrackIdsAsArray();
		$trackIds = \array_diff_key($trackIds, \array_flip($trackIndices));
		$playlist->setTrackIdsFromArray($trackIds);
		$this->mapper->update($playlist);
		return $playlist;
	}

	public function removeAllTracks(int $playlistId, string $userId) : Playlist {
		$playlist = $this->find($playlistId, $userId);
		$playlist->setTrackIdsFromArray([]);
		$this->mapper->update($playlist);
		return $playlist;
	}

	public function moveTrack(int $fromIndex, int $toIndex, int $playlistId, string $userId) : Playlist {
		$playlist = $this->find($playlistId, $userId);
		$trackIds = $playlist->getTrackIdsAsArray();
		$movedTrack = \array_splice($trackIds, $fromIndex, 1);
		\array_splice($trackIds, $toIndex, 0, $movedTrack);
		$playlist->setTrackIdsFromArray($trackIds);
		$this->mapper->update($playlist);
		return $playlist;
	}

	public function create(string $name, string $userId) : Playlist {
		$playlist = new Playlist();
		$playlist->setName(Util::truncate($name, 256)); // some DB setups can't truncate automatically to column max size
		$playlist->setUserId($userId);

		return $this->mapper->insert($playlist);
	}

	public function rename(string $name, int $playlistId, string $userId) : Playlist {
		$playlist = $this->find($playlistId, $userId);
		$playlist->setName(Util::truncate($name, 256)); // some DB setups can't truncate automatically to column max size
		$this->mapper->update($playlist);
		return $playlist;
	}

	public function setComment(string $comment, int $playlistId, string $userId) : Playlist {
		$playlist = $this->find($playlistId, $userId);
		$playlist->setComment(Util::truncate($comment, 256)); // some DB setups can't truncate automatically to column max size
		$this->mapper->update($playlist);
		return $playlist;
	}

	/**
	 * removes tracks from all available playlists
	 * @param int[] $trackIds array of all track IDs to remove
	 */
	public function removeTracksFromAllLists(array $trackIds) : void {
		foreach ($trackIds as $trackId) {
			$affectedLists = $this->mapper->findListsContainingTrack($trackId);

			foreach ($affectedLists as $playlist) {
				$prevTrackIds = $playlist->getTrackIdsAsArray();
				$playlist->setTrackIdsFromArray(\array_diff($prevTrackIds, [$trackId]));
				$this->mapper->update($playlist);
			}
		}
	}

	/**
	 * get list of Track objects belonging to a given playlist
	 * @return Track[]
	 */
	public function getPlaylistTracks(int $playlistId, string $userId, ?int $limit=null, ?int $offset=null) : array {
		$playlist = $this->find($playlistId, $userId);
		$trackIds = $playlist->getTrackIdsAsArray();

		$trackIds = \array_slice($trackIds, \intval($offset), $limit);

		$tracks = empty($trackIds) ? [] : $this->trackMapper->findById($trackIds, $userId);

		// The $tracks contains the songs in unspecified order and with no duplicates.
		// Build a new array where the tracks are in the same order as in $trackIds.
		$tracksById = Util::createIdLookupTable($tracks);

		$playlistTracks = [];
		foreach ($trackIds as $index => $trackId) {
			$track = $tracksById[$trackId] ?? null;
			if ($track !== null) {
				// in case the same track comes up again in the list, clone the track object
				// to have different numbers on the instances
				if ($track->getNumberOnPlaylist() !== null) {
					$track = clone $track;
				}
				$track->setNumberOnPlaylist(\intval($offset) + $index + 1);
			} else {
				$this->logger->log("Invalid track ID $trackId found on playlist $playlistId", 'debug');
				$track = new Track();
				$track->setId($trackId);
			}
			$playlistTracks[] = $track;
		}

		return $playlistTracks;
	}

	/**
	 * get the total duration of all the tracks on a playlist
	 *
	 * @return int duration in seconds
	 */
	public function getDuration(int $playlistId, string $userId) : int {
		$playlist = $this->find($playlistId, $userId);
		$trackIds = $playlist->getTrackIdsAsArray();
		$durations = $this->trackMapper->getDurations($trackIds);

		// We can't simply sum up the values of $durations array, because the playlist may
		// contain duplicate entries, and those are not reflected in $durations.
		// Be also prepared to invalid playlist entries where corresponding track length does not exist.
		$sum = 0;
		foreach ($trackIds as $trackId) {
			$sum += $durations[$trackId] ?? 0;
		}

		return $sum;
	}

	/**
	 * Generate and return a playlist matching the given criteria. The playlist is not persisted.
	 *
	 * @param string|null $playRate One of: 'recent', 'not-recent', 'often', 'rarely'
	 * @param int[] $genres Array of genre IDs
	 * @param int[] $artists Array of artist IDs
	 * @param int|null $fromYear Earliest release year to include
	 * @param int|null $toYear Latest release year to include
	 * @param int $size Size of the playlist to generate, provided that there are enough matching tracks
	 * @param string $userId the name of the user
	 */
	public function generate(?string $playRate, array $genres, array $artists, ?int $fromYear, ?int $toYear, int $size, string $userId) : Playlist {
		$now = new \DateTime();
		$nowStr = $now->format(PlaylistMapper::SQL_DATE_FORMAT);

		$playlist = new Playlist();
		$playlist->setCreated($nowStr);
		$playlist->setUpdated($nowStr);
		$playlist->setName('Generated ' . $nowStr);
		$playlist->setUserId($userId);

		list('sortBy' => $sortBy, 'invert' => $invertSort) = self::sortRulesForPlayRate($playRate);
		$limit = ($sortBy === SortBy::None) ? null : $size * 4;

		$tracks = $this->trackMapper->findAllByCriteria($genres, $artists, $fromYear, $toYear, $sortBy, $invertSort, $userId, $limit);

		if ($sortBy !== SortBy::None) {
			// When generating by play-rate, use a pool of tracks at maximum twice the size of final list. However, don't use
			// more than half of the matching tracks unless that is required to satisfy the required list size.
			$poolSize = max($size, \count($tracks) / 2);
			$tracks = \array_slice($tracks, 0, $poolSize);
		}

		// Pick the final random set of tracks
		$tracks = Random::pickItems($tracks, $size);

		$playlist->setTrackIdsFromArray(Util::extractIds($tracks));

		return $playlist;
	}

	private static function sortRulesForPlayRate(?string $playRate) : array {
		switch ($playRate) {
			case 'recently':
				return ['sortBy' => SortBy::LastPlayed, 'invert' => true];
			case 'not-recently':
				return ['sortBy' => SortBy::LastPlayed, 'invert' => false];
			case 'often':
				return ['sortBy' => SortBy::PlayCount, 'invert' => true];
			case 'rarely':
				return ['sortBy' => SortBy::PlayCount, 'invert' => false];
			default:
				return ['sortBy' => SortBy::None, 'invert' => false];
		}
	}
}
