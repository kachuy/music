<?php

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2017 - 2020
 */

namespace OCA\Music\Utility;

use \OCA\Music\AppFramework\Core\Logger;
use \OCA\Music\Db\Album;
use \OCA\Music\Db\Artist;
use \OCA\Music\Db\Cache;

use \OCP\Files\Folder;
use \OCP\Files\File;

use \OCP\IConfig;

use \Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Instantiator\Exception\InvalidArgumentException;

/**
 * utility to get cover image for album
 */
class CoverHelper {
	private $extractor;
	private $cache;
	private $coverSize;
	private $logger;

	const MAX_SIZE_TO_CACHE = 102400;

	public function __construct(
			Extractor $extractor,
			Cache $cache,
			IConfig $config,
			Logger $logger) {
		$this->extractor = $extractor;
		$this->cache = $cache;
		$this->logger = $logger;

		// Read the cover size to use from config.php or use the default
		$this->coverSize = intval($config->getSystemValue('music.cover_size')) ?: 380;
	}

	/**
	 * Get cover image of an album or and artist
	 *
	 * @param Entity $entity Album or Artist
	 * @param string $userId
	 * @param Folder $rootFolder
	 * @param int|null $size
	 * @return array|null Image data in format accepted by \OCA\Music\Http\FileResponse
	 */
	public function getCover($entity, $userId, $rootFolder, $size=null) {
		// Skip using cache in case the cover is requested in specific size
		if ($size) {
			return $this->readCover($entity, $userId, $rootFolder, $size);
		} else {
			$dataAndHash = $this->getCoverAndHash($entity, $userId, $rootFolder);
			return $dataAndHash['data'];
		}
	}

	/**
	 * Get cover image of an album or and artist along with the image's hash
	 * 
	 * The hash is non-null only in case the cover is/was cached.
	 *
	 * @param Entity $entity Album or Artist
	 * @param string $userId
	 * @param Folder $rootFolder
	 * @return array Dictionary with keys 'data' and 'hash'
	 */
	public function getCoverAndHash($entity, $userId, $rootFolder) {
		$hash = $this->cache->get($userId, self::getHashKey($entity));
		$data = null;

		if ($hash !== null) {
			$data = $this->getCoverFromCache($hash, $userId);
		}
		if ($data === null) {
			$hash = null;
			$data = $this->readCover($entity, $userId, $rootFolder, $this->coverSize);
			if ($data !== null) {
				$hash = $this->addCoverToCache($entity, $userId, $data);
			}
		}

		return ['data' => $data, 'hash' => $hash];
	}

	/**
	 * Get all album cover hashes for one user.
	 * @param string $userId
	 * @return array with album IDs as keys and hashes as values
	 */
	public function getAllCachedAlbumCoverHashes($userId) {
		$rows = $this->cache->getAll($userId, 'album_cover_hash_');
		$hashes = [];
		foreach ($rows as $row) {
			$albumId = \explode('_', $row['key'])[1];
			$hashes[$albumId] = $row['data'];
		}
		return $hashes;
	}

	/**
	 * Get cover image with given hash from the cache
	 *
	 * @param string $hash
	 * @param string $userId
	 * @param bool $asBase64
	 * @return array|null Image data in format accepted by \OCA\Music\Http\FileResponse
	 */
	public function getCoverFromCache($hash, $userId, $asBase64 = false) {
		$cached = $this->cache->get($userId, 'cover_' . $hash);
		if ($cached !== null) {
			$delimPos = \strpos($cached, '|');
			$mime = \substr($cached, 0, $delimPos);
			$content = \substr($cached, $delimPos + 1);
			if (!$asBase64) {
				$content = \base64_decode($content);
			}
			return ['mimetype' => $mime, 'content' => $content];
		}
		return null;
	}

	/**
	 * Cache the given cover image data
	 * @param Entity $entity Album or Artist
	 * @param string $userId
	 * @param array $coverData
	 * @return string|null Hash of the cached cover
	 */
	private function addCoverToCache($entity, $userId, $coverData) {
		$mime = $coverData['mimetype'];
		$content = $coverData['content'];
		$hash = null;
		$hashKey = self::getHashKey($entity);

		if ($mime && $content) {
			$size = \strlen($content);
			if ($size < self::MAX_SIZE_TO_CACHE) {
				$hash = \hash('md5', $content);
				// cache the data with hash as a key
				try {
					$this->cache->add($userId, 'cover_' . $hash, $mime . '|' . \base64_encode($content));
				} catch (UniqueConstraintViolationException $ex) {
					$this->logger->log("Cover with hash $hash is already cached", 'debug');
				}
				// cache the hash with hashKey as a key
				try {
					$this->cache->add($userId, $hashKey, $hash);
				} catch (UniqueConstraintViolationException $ex) {
					$this->logger->log("Cover hash with key $hashKey is already cached", 'debug');
				}
				// collection.json needs to be regenrated the next time it's fetched
				$this->cache->remove($userId, 'collection');
			} else {
				$this->logger->log("Cover image of entity with key $hashKey is large ($size B), skip caching", 'debug');
			}
		}

		return $hash;
	}

	/**
	 * Remove album cover image from cache if it is there. Silently do nothing if there
	 * is no cached cover.
	 * @param int $albumId
	 * @param string $userId
	 */
	public function removeAlbumCoverFromCache($albumId, $userId) {
		$this->cache->remove($userId, 'album_cover_hash_' . $albumId);
	}

	/**
	 * Remove artist cover image from cache if it is there. Silently do nothing if there
	 * is no cached cover.
	 * @param int $artistId
	 * @param string $userId
	 */
	public function removeArtistCoverFromCache($artistId, $userId) {
		$this->cache->remove($userId, 'artist_cover_hash_' . $artistId);
	}

	/**
	 * Read cover image from the file system
	 * @param Enity $entity Album or Artist entity
	 * @param string $userId
	 * @param Folder $rootFolder
	 * @param int $size Maximum size for the image to read, larger images are scaled down
	 * @return array|null Image data in format accepted by \OCA\Music\Http\FileResponse
	 */
	private function readCover($entity, $userId, $rootFolder, $size) {
		$response = null;
		$coverId = $entity->getCoverFileId();

		if ($coverId > 0) {
			$nodes = $rootFolder->getById($coverId);
			if (\count($nodes) > 0) {
				// get the first valid node (there shouldn't be more than one node anyway)
				/* @var $node File */
				$node = $nodes[0];
				$mime = $node->getMimeType();

				if (\strpos($mime, 'audio') === 0) { // embedded cover image
					$cover = $this->extractor->parseEmbeddedCoverArt($node); // TODO: currently only album cover supported

					if ($cover !== null) {
						$response = ['mimetype' => $cover['image_mime'], 'content' => $cover['data']];
					}
				} else { // separate image file
					$response = ['mimetype' => $mime, 'content' => $node->getContent()];
				}
			}

			if ($response === null) {
				$class = \get_class($entity);
				$this->logger->log("Requested cover not found for $class entity {$entity->getId()}, coverId=$coverId", 'error');
			} else {
				$response['content'] = $this->scaleDownAndCrop($response['content'], $size);
			}
		}

		return $response;
	}

	/**
	 * Scale down images to reduce size and crop to square shape
	 *
	 * If one of the dimensions of the image is smaller than the maximum, then just
	 * crop to square shape but do not scale.
	 * @param string $image The image to be scaled down as string
	 * @param integer $maxSize The maximum size in pixels for the square shaped output
	 * @return string The processed image as string
	 */
	public function scaleDownAndCrop($image, $maxSize) {
		$meta = \getimagesizefromstring($image);
		$srcWidth = $meta[0];
		$srcHeight = $meta[1];

		// only process picture if it's larger than target size or not perfect square
		if ($srcWidth > $maxSize || $srcHeight > $maxSize || $srcWidth != $srcHeight) {
			$img = imagecreatefromstring($image);

			if ($img === false) {
				$this->logger->log('Failed to open cover image for downscaling', 'warning');
			}
			else {
				$srcCropSize = \min($srcWidth, $srcHeight);
				$srcX = ($srcWidth - $srcCropSize) / 2;
				$srcY = ($srcHeight - $srcCropSize) / 2;

				$dstSize = \min($maxSize, $srcCropSize);
				$scaledImg = \imagecreatetruecolor($dstSize, $dstSize);
				\imagecopyresampled($scaledImg, $img, 0, 0, $srcX, $srcY, $dstSize, $dstSize, $srcCropSize, $srcCropSize);
				\imagedestroy($img);

				\ob_start();
				\ob_clean();
				$mime = $meta['mime'];
				switch ($mime) {
					case 'image/jpeg':
						imagejpeg($scaledImg, null, 75);
						$image = \ob_get_contents();
						break;
					case 'image/png':
						imagepng($scaledImg, null, 7, PNG_ALL_FILTERS);
						$image = \ob_get_contents();
						break;
					case 'image/gif':
						imagegif($scaledImg, null);
						$image = \ob_get_contents();
						break;
					default:
						$this->logger->log("Cover image type $mime not supported for downscaling", 'warning');
						break;
				}
				\ob_end_clean();
				\imagedestroy($scaledImg);
			}
		}
		return $image;
	}

	/**
	 * @param Entity $entity An Album or Artist entity
	 * @throws InvalidArgumentException if entity is not one of the expected types
	 * @return string
	 */
	private static function getHashKey($entity) {
		if ($entity instanceof Album) {
			return 'album_cover_hash_' . $entity->getId();
		} elseif ($entity instanceof Artist) {
			return 'artist_cover_hash_' . $entity->getId();
		} else {
			throw new \InvalidArgumentException('Unexpected entity type');
		}
	}
}
