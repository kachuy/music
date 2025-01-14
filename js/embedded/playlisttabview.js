/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020 - 2023
 */

OCA.Music = OCA.Music || {};

OCA.Music.initPlaylistTabView = function(playlistMimes) {
	if (typeof OCA.Files.DetailTabView != 'undefined') {
		OCA.Music.PlaylistTabView = OCA.Files.DetailTabView.extend({
			id: 'musicPlaylistTabView',
			className: 'tab musicPlaylistTabView',

			getLabel: function() {
				return t('music', 'Playlist');
			},

			getIcon: function() {
				return 'icon-music';
			},

			render: function() {
				let container = this.$el;
				container.empty(); // erase any previous content

				let fileInfo = this.getFileInfo();

				if (fileInfo) {

					let loadIndicator = $(document.createElement('div')).attr('class', 'loading');
					container.append(loadIndicator);

					let onPlaylistLoaded = (data) => {
						loadIndicator.hide();

						let list = $(document.createElement('ol'));
						container.append(list);

						let titleForFile = function(file) {
							return file.caption || OCA.Music.Utils.titleFromFilename(file.name);
						};

						let tooltipForFile = function(file) {
							return file.url || `${file.path}/${file.name}`;
						};

						for (let i = 0; i < data.files.length; ++i) {
							list.append($(document.createElement('li'))
										.attr('id', 'music-playlist-item-' + i)
										.text(titleForFile(data.files[i]))
										.prop('title', tooltipForFile(data.files[i])));
						}

						// click handler
						list.on('click', 'li', (event) => {
							let id = event.target.id;
							let idx = parseInt(id.split('-').pop());
							this.trigger('playlistItemClick', fileInfo.id, fileInfo.attributes.name, idx);
						});

						if (data.invalid_paths.length > 0) {
							container.append($(document.createElement('p')).text(t('music', 'Some files on the playlist were not found') + ':'));
							let failList = $(document.createElement('ul'));
							container.append(failList);

							for (let i = 0; i < data.invalid_paths.length; ++i) {
								failList.append($(document.createElement('li')).text(data.invalid_paths[i]));
							}
						}

						this.trigger('rendered');
					};

					let onError = function(_error) {
						loadIndicator.hide();
						container.append($(document.createElement('p')).text(t('music', 'Error reading playlist file')));
					};

					OCA.Music.PlaylistFileService.readFile(fileInfo.id, onPlaylistLoaded, onError);
				}
			},

			canDisplay: function(fileInfo) {
				if (!fileInfo || fileInfo.isDirectory()) {
					return false;
				}
				let mimetype = fileInfo.get('mimetype');

				return (mimetype && playlistMimes.indexOf(mimetype) > -1);
			},

			setCurrentTrack: function(playlistId, trackIndex) {
				this.$el.find('ol li.current').removeClass('current');
				let fileInfo = this.getFileInfo();
				if (fileInfo && fileInfo.id == playlistId) {
					this.$el.find('ol li#music-playlist-item-' + trackIndex).addClass('current');
				}
			}
		});
		_.extend(OCA.Music.PlaylistTabView.prototype, OC.Backbone.Events);
		OCA.Music.playlistTabView = new OCA.Music.PlaylistTabView();

		OC.Plugins.register('OCA.Files.FileList', {
			attach: function(fileList) {
				fileList.registerTabView(OCA.Music.playlistTabView);
			}
		});
	}
};
