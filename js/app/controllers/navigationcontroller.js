/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013
 * @copyright Pauli Järvinen 2017 - 2023
 */


angular.module('Music').controller('NavigationController', [
	'$rootScope', '$scope', '$document', 'Restangular', '$timeout', '$location',
	'playlistService', 'playlistFileService', 'podcastService', 'libraryService', 'gettextCatalog',
	function ($rootScope, $scope, $document, Restangular, $timeout, $location,
			playlistService, playlistFileService, podcastService, libraryService, gettextCatalog) {

		$rootScope.loading = true;

		$scope.newPlaylistName = '';
		$scope.newPlaylistTrackIds = [];
		$scope.popupShownForNaviItem = null;
		$scope.radioBusy = false;
		$scope.podcastsBusy = false;

		// holds the state of the editor (visible or not)
		$scope.showCreateForm = false;
		// same as above, but for the playlist renaming. Holds the number of the playlist, which is currently edited
		$scope.showEditForm = null;

		// hide 'more' popup menu of a playlist when user clicks anywhere on the page
		$document.click(function(_event) {
			$timeout(() => $scope.popupShownForNaviItem = null);
		});

		// Start creating playlist
		$scope.startCreate = function() {
			$scope.showCreateForm = true;
			// Move the focus to the input field. This has to be made asynchronously
			// because the field is not visible yet, it is shown by ng-show binding
			// later during this digest loop.
			$timeout(() => $('.new-list').focus());
		};

		// Commit creating playlist
		$scope.commitCreate = function() {
			if ($scope.newPlaylistName.length > 0) {
				createPlaylist($scope.newPlaylistName, $scope.newPlaylistTrackIds);
				$scope.closeCreate();
			}
		};

		$scope.closeCreate = function() {
			$scope.newPlaylistName = '';
			$scope.newPlaylistTrackIds = [];
			$scope.showCreateForm = false;
		};

		// Show/hide the more actions menu on a navigation item
		$scope.onNaviItemMoreButton = function(naviDestination) {
			if ($scope.popupShownForNaviItem == naviDestination) {
				$scope.popupShownForNaviItem = null;
			} else {
				$scope.popupShownForNaviItem = naviDestination;

				// clicking on any action in the popup closes the popup menu and stops the propagation (to avoid the unwanted view switches)
				$('.popovermenu').off('click');
				$('.popovermenu').on('click', 'li', function(event) {
					$timeout(() => $scope.popupShownForNaviItem = null);
					event.stopPropagation();
				});
			}
		};

		$scope.showDetails = function(playlist) {
			$rootScope.$emit('showPlaylistDetails', playlist.id);
			$scope.collapseNavigationPaneOnMobile();
		};

		// Start renaming playlist
		$scope.startEdit = function(playlist) {
			$scope.showEditForm = playlist.id;
			// Move the focus to the input field. This has to be made asynchronously
			// because the field does not exist yet, it is added by ng-if binding
			// later during this digest loop.
			$timeout(() => $('.edit-list').focus());
		};

		// Commit renaming of playlist
		$scope.commitEdit = function(playlist) {
			if (playlist.name.length > 0) {
				Restangular.one('playlists', playlist.id).customPUT({name: playlist.name}).then(function (result) {
					playlist.updated = result.updated;
				});
				$scope.showEditForm = null;
				libraryService.sortPlaylists();
				$timeout(() => $scope.navigateTo(`#playlist/${playlist.id}`));
			}
		};

		// Sort playlist
		$scope.sortPlaylist = function(playlist, byProperty) {
			libraryService.sortPlaylist(playlist.id, byProperty);
			handlePlaylistContentChange(playlist);
		};

		// Remove duplicate tracks from a playlist
		$scope.removeDuplicates = function(playlist) {
			let removedTracks = libraryService.removeDuplicatesFromPlaylist(playlist.id);
			let removedCount = removedTracks.length;
			let message = gettextCatalog.getPlural(removedCount,
													'{{ count }} duplicate track was removed',
													'{{ count }} duplicate tracks were removed',
													{ count: removedCount });
			OC.Notification.showTemporary(message);

			if (removedCount > 0) {
				handlePlaylistContentChange(playlist);
			}
		};

		// Remove playlist
		$scope.remove = function(playlist) {
			OC.dialogs.confirm(
					gettextCatalog.getString('Are you sure to remove the playlist "{{ name }}"?', { name: playlist.name }),
					gettextCatalog.getString('Remove playlist'),
					function(confirmed) {
						if (confirmed) {
							Restangular.one('playlists', playlist.id).remove();

							// remove the elemnt also from the AngularJS list
							libraryService.removePlaylist(playlist);
						}
					},
					true
				);
		};

		// Export playlist to file
		$scope.exportToFile = function(playlist) {
			playlistFileService.exportPlaylist(playlist);
		};

		// Import playlist contents from a file
		$scope.importFromFile = function(playlist) {
			playlistFileService.importPlaylist(playlist);
		};

		// Export radio stations to a file
		$scope.exportRadioToFile = function() {
			playlistFileService.exportRadio().then(
				() => $scope.radioBusy = false, // success
				() => $scope.radioBusy = false, // failure
				(state) => { // notification about state change
					if (state == 'started') {
						$scope.radioBusy = true;
					} else if (state == 'stopped') {
						$scope.radioBusy = false;
					}
				}
			);
		};

		// Import radio stations from a playlist file
		$scope.importFromFileToRadio = function() {
			playlistFileService.importRadio().then(
				() => $scope.radioBusy = false, // success
				() => $scope.radioBusy = false, // failure
				() => $scope.radioBusy = true   // notification about import actually starting
			);
		};

		$scope.addRadio = function() {
			$rootScope.$emit('showRadioStationDetails', null);
		};

		$scope.addPodcast = function() {
			podcastService.showAddPodcastDialog().then(
				() => $scope.podcastsBusy = false, // success
				() => $scope.podcastsBusy = false, // failure
				() => $scope.podcastsBusy = true,  // started
			);
		};

		$scope.reloadPodcasts = function(event) {
			if ($scope.anyPodcastChannels()) {
				$scope.podcastsBusy = true;
				podcastService.reloadAllPodcasts().then(() => $scope.podcastsBusy = false);
			} else {
				event.stopPropagation();
			}
		};

		$scope.anyPodcastChannels = function() {
			return libraryService.getPodcastChannelsCount() > 0;
		};

		$scope.reloadSmartListView = function() {
			$scope.reloadSmartList();

			// also navigate to the Smart Playlist view if not already open
			$scope.navigateTo('#smartlist');
		};

		$scope.saveSmartList = function() {
			const smartlist = libraryService.getSmartList();
			createPlaylist(
				gettextCatalog.getString('Generated {{ datetime }}', { datetime: OCA.Music.Utils.formatDateTime(smartlist.created) }),
				_.map(smartlist.tracks, 'track.id')
			);
		};

		// Play/pause playlist
		$scope.togglePlay = function(destination, playlist) {
			if ($rootScope.playingView == destination) {
				playlistService.publish('togglePlayback');
			}
			else {
				let play = function(id, tracks) {
					if (tracks && tracks.length) {
						playlistService.setPlaylist(id, tracks);
						playlistService.publish('play', destination);
					}
				};

				if (destination == '#') {
					play('albums', libraryService.getTracksInAlbumOrder());
				} else if (destination == '#/alltracks') {
					play('alltracks', libraryService.getTracksInAlphaOrder());
				} else if (destination == '#/smartlist') {
					play('smartlist', libraryService.getSmartList().tracks);
				} else if (destination == '#/folders') {
					$scope.$parent.loadFoldersAndThen(function() {
						play('folders', libraryService.getTracksInFolderOrder(!$scope.foldersFlatLayout));
					});
				} else if (destination == '#/genres') {
					play('genres', libraryService.getTracksInGenreOrder());
				} else if (destination === '#/radio') {
					play('radio', libraryService.getAllRadioStations());
				} else if (destination === '#/podcasts') {
					play('podcasts', _.map(libraryService.getAllPodcastEpisodes(), (episode) => ({track: episode})));
				} else {
					play('playlist-' + playlist.id, playlist.tracks);
				}
			}
		};

		// Add track to the playlist
		$scope.addTrack = function(playlist, songId) {
			addTracks(playlist, [songId]);
		};

		// Add all tracks on an album to the playlist
		$scope.addAlbum = function(playlist, albumId) {
			addTracks(playlist, trackIdsFromAlbum(albumId));
		};

		// Add all tracks on all albums by an artist to the playlist
		$scope.addArtist = function(playlist, artistId) {
			addTracks(playlist, trackIdsFromArtist(artistId));
		};

		// Add all tracks in a folder to the playlist
		$scope.addFolder = function(playlist, folderId) {
			addTracks(playlist, trackIdsFromFolder(folderId));
		};

		// Add all tracks of the genre to the playlist
		$scope.addGenre = function(playlist, genreId) {
			addTracks(playlist, trackIdsFromGenre(genreId));
		};

		// An item dragged and dropped on a navigation bar playlist item
		$scope.dropOnPlaylist = function(droppedItem, playlist) {
			if ('track' in droppedItem) {
				$scope.addTrack(playlist, droppedItem.track);
			} else if ('album' in droppedItem) {
				$scope.addAlbum(playlist, droppedItem.album);
			} else if ('artist' in droppedItem) {
				$scope.addArtist(playlist, droppedItem.artist);
			} else if ('folder' in droppedItem) {
				$scope.addFolder(playlist, droppedItem.folder);
			} else if ('genre' in droppedItem) {
				$scope.addGenre(playlist, droppedItem.genre);
			} else {
				console.error('Unknwon entity dropped on playlist');
			}

			// 50 ms haptic feedback for touch devices
			if ('vibrate' in navigator) {
				navigator.vibrate(50);
			}
		};

		$scope.allowDrop = function(playlist, draggable) {
			// Don't allow dragging a track from a playlist back to the same playlist
			let isFromPlaylist = ('srcIndex' in draggable);
			let targetIsCurrentPlaylist = ($rootScope.currentView == '#/playlist/' + playlist.id);
			return !isFromPlaylist || !targetIsCurrentPlaylist;
		};

		// Dragging an entity over the navigation toggle pops the navigation pane open.
		// Subsequently, ending the drag closes the navigation pane.
		let navOpenedByDrag = false;
		const navToggle = document.getElementById('app-navigation-toggle');
		navToggle.addEventListener('dragenter', function() {
			if (!navOpenedByDrag) {
				navOpenedByDrag = true;
				$timeout(() => $(navToggle).click());
			}
		});
		document.addEventListener('dragend', function() {
			if (navOpenedByDrag) { 
				navOpenedByDrag = false;
				$scope.collapseNavigationPaneOnMobile();
			}
		});

		function createPlaylist(name, trackIds) {
			const args = {
				name: name,
				trackIds: trackIds.join(',')
			};
			Restangular.all('playlists').post(args).then(function(playlist) {
				libraryService.addPlaylist(playlist);
				$timeout(() => $scope.navigateTo(`#playlist/${playlist.id}`));
			});
		}

		function trackIdsFromAlbum(albumId) {
			let album = libraryService.getAlbum(albumId);
			return _.map(album.tracks, 'id');
		}

		function trackIdsFromArtist(artistId) {
			let artist = libraryService.getArtist(artistId);
			return _(artist.albums).map('id').map(trackIdsFromAlbum).flatten().value();
		}

		function trackIdsFromFolder(folderId) {
			let folder = libraryService.getFolder(folderId);
			let tracks = libraryService.getFolderTracks(folder, !$scope.foldersFlatLayout);
			return _.map(tracks, 'track.id');
		}

		function trackIdsFromGenre(genreId) {
			let genre = libraryService.getGenre(genreId);
			return _.map(genre.tracks, 'track.id');
		}

		function addTracks(playlist, trackIds) {
			if (playlist === null) {
				// tracks dropped on the "+ New Playlist" item, start creating a new list
				$scope.newPlaylistTrackIds = $scope.newPlaylistTrackIds.concat(trackIds);
				$scope.startCreate();
			}
			else {
				_.forEach(trackIds, function(trackId) {
					libraryService.addToPlaylist(playlist.id, trackId);
				});

				// Update the currently playing list if necessary
				if ($rootScope.playingView == '#/playlist/' + playlist.id) {
					let newTracks = _.map(trackIds, function(trackId) {
						return { track: libraryService.getTrack(trackId) };
					});
					playlistService.onTracksAdded(newTracks);
				}

				Restangular.one('playlists', playlist.id).all('add').post({trackIds: trackIds.join(',')}).then(function (result) {
					playlist.updated = result.updated;
				});
			}
		}

		function handlePlaylistContentChange(playlist) {
			// Update the currently playing list if necessary
			if ($rootScope.playingView == '#/playlist/' + playlist.id) {
				let playingIndex = _.findIndex(playlist.tracks, { track: $scope.currentTrack });
				playlistService.onPlaylistModified(playlist.tracks, playingIndex);
			}

			let trackIds = _.map(playlist.tracks, 'track.id');
			Restangular.one('playlists', playlist.id).customPUT({ trackIds: trackIds.join(',') }).then(function (result) {
				playlist.updated = result.updated;
			});
		}

		$rootScope.$on('viewActivated', function() {
			// start playing the current view if the 'autoplay' argument is present in the URL and has a truthy value
			if (OCA.Music.Utils.parseBoolean($location.search().autoplay)) {
				if (!$rootScope.playing) {
					let playlist = null;
					if ($rootScope.currentView.startsWith('#/playlist/')) {
						let id = _.last($rootScope.currentView.split('/'));
						playlist = libraryService.getPlaylist(id);
					}
					$scope.togglePlay($rootScope.currentView, playlist);
				}
			}
			// ensure that the link to the current view is visible in the navigation pane, 
			// in case there are so many playlists that the navigation pane is scrollable
			$timeout(() => {
				const navPaneContent = $('#app-navigation ul');
				const navItem = navPaneContent.find('.music-navigation-item.active');
				if (navItem.length) { // navItem is empty when Settings view activated
					const navItemTop = navItem.offset().top - $('#header').height();
					const navItemBottom = navItemTop + navItem.height();
					if (navItemTop < 0 || navItemBottom > navPaneContent.innerHeight()) {
						navPaneContent.scrollToElement(navItem, 0, 300);
					}
				}
			});
		});
	}
]);
