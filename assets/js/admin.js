(function($) {
	'use strict';

	var Jetonomy = {
		nonce: typeof jetonomyAdmin !== 'undefined' ? jetonomyAdmin.nonce : '',
		ajaxUrl: typeof jetonomyAdmin !== 'undefined' ? jetonomyAdmin.ajaxUrl : '',
		i18n: typeof jetonomyAdmin !== 'undefined' ? jetonomyAdmin.i18n : {},

		init: function() {
			this.bindDashboard();
			this.bindCategoryActions();
			this.bindSpaceActions();
			this.bindInviteLinks();
			this.bindModerationActions();
			this.bindUserActions();
			this.bindImport();
			this.bindSettings();
			this.initColorPickers();
			this.initCodeEditor();
			this.initMediaUploaders();
			this.bindSlugGeneration();
		},

		// ── List page context ──

		// Drag-reorder submits only the rows the browser rendered, so any handler
		// persisting a position needs to know which page those rows came from.
		// Read from the URL rather than the DOM: it is the same source the server
		// paginated with, so the two can never disagree.
		listPageContext: function() {
			var params = new URLSearchParams(window.location.search);
			var paged = parseInt(params.get('paged'), 10);
			var perPage = parseInt(params.get('per_page'), 10);
			return {
				paged: paged > 0 ? paged : 1,
				perPage: [20, 50, 100].indexOf(perPage) !== -1 ? perPage : 20
			};
		},

		// ── AJAX Helper ──

		ajax: function(action, data) {
			data = data || {};
			data.action = action;
			data.nonce = this.nonce;
			return $.post(this.ajaxUrl, data);
		},

		// ── Confirm helper (1.4.0) ──
		// Wraps the shared modal toolkit (assets/js/jetonomy-modals.js) so
		// every wp-admin "Are you sure?" prompt uses the same dialog as
		// the front end. Returns a Promise resolving true/false. Per the
		// no-browser-alerts rule, we NEVER fall back to native confirm()
		// even on wp-admin — the toolkit is a hard dependency. If it's
		// somehow absent the helper resolves false (cancel) so the
		// destructive action never silently runs.
		confirmAsync: function(message, opts) {
			if (typeof window.jetonomyConfirm !== 'function') {
				return Promise.resolve(false);
			}
			return window.jetonomyConfirm(message, opts || {});
		},

		// ── Toast Notification ──

		toast: function(message, type) {
			type = type || 'success';
			var $toast = $('<div class="jetonomy-toast jetonomy-toast--' + type + '">' + $('<span>').text(message).html() + '</div>');
			$('body').append($toast);
			setTimeout(function() {
				$toast.remove();
			}, 3200);
		},

		// ── Spinner Helper ──

		showSpinner: function($el) {
			$el.closest('.submit, p, .jetonomy-modal__actions').find('.spinner').addClass('is-active');
		},

		hideSpinner: function($el) {
			$el.closest('.submit, p, .jetonomy-modal__actions').find('.spinner').removeClass('is-active');
		},

		// ── Slug Auto-generation ──

		bindSlugGeneration: function() {
			$(document).on('blur', '#cat-name', function() {
				var $slug = $('#cat-slug');
				if (!$slug.val()) {
					$slug.val(Jetonomy.slugify($(this).val()));
				}
			});
			$(document).on('blur', '#space-title', function() {
				var $slug = $('#space-slug');
				if (!$slug.val()) {
					$slug.val(Jetonomy.slugify($(this).val()));
				}
			});
		},

		slugify: function(text) {
			return text.toString().toLowerCase().trim()
				.replace(/[^\w\s-]/g, '')
				.replace(/[\s_]+/g, '-')
				.replace(/^-+|-+$/g, '');
		},

		// ── Color Pickers ──

		initColorPickers: function() {
			$('.jetonomy-color-picker').each(function() {
				if (!$(this).hasClass('wp-color-picker')) {
					$(this).wpColorPicker();
				}
			});
		},

		// ── Code Editor ──

		initCodeEditor: function() {
			if (typeof jetonomyCmSettings !== 'undefined' && $('#custom_css').length) {
				wp.codeEditor.initialize($('#custom_css'), jetonomyCmSettings);
			}
		},

		// ── Media Uploaders ──

		initMediaUploaders: function() {
			var self = this;

			$(document).on('click', '#space-cover-upload', function(e) {
				e.preventDefault();
				var frame = wp.media({
					title: self.i18n.selectImage,
					button: { text: self.i18n.useImage },
					multiple: false,
					library: { type: 'image' }
				});

				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					$('#space-cover-image').val(attachment.url);
					var $preview = $('#space-cover-preview');
					if ($preview.find('img').length) {
						$preview.find('img').attr('src', attachment.url);
					} else {
						$preview.prepend('<img src="' + attachment.url + '" alt="">');
					}
					$preview.show();
				});

				frame.open();
			});

			$(document).on('click', '.jetonomy-remove-cover', function(e) {
				e.preventDefault();
				$('#space-cover-image').val('');
				$('#space-cover-preview').hide().find('img').attr('src', '');
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Dashboard
		// ═══════════════════════════════════════════════════════════

		bindDashboard: function() {
			var self = this;

			$(document).on('click', '#jetonomy-flush-rules', function() {
				var $btn = $(this);
				$btn.prop('disabled', true);
				self.ajax('jetonomy_flush_rules').done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Categories
		// ═══════════════════════════════════════════════════════════

		bindCategoryActions: function() {
			var self = this;

			// Create Category
			$(document).on('click', '#jetonomy-save-category', function() {
				var $btn = $(this);
				var name = $('#cat-name').val().trim();
				if (!name) {
					$('#cat-name').focus();
					return;
				}

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_create_category', {
					name: name,
					slug: $('#cat-slug').val(),
					description: $('#cat-description').val(),
					parent_id: $('#cat-parent').val(),
					icon: ($('input[name="icon"]', '#jetonomy-add-category-form').filter(':checked').val() || ''),
					color: $('#cat-color').val(),
					visibility: $('#cat-visibility').val()
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						location.reload();
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// Edit Category - Open Modal
			$(document).on('click', '.jetonomy-edit-category', function(e) {
				e.preventDefault();
				var $link = $(this);
				$('#edit-cat-id').val($link.data('id'));
				$('#edit-cat-name').val($link.data('name'));
				$('#edit-cat-slug').val($link.data('slug'));
				$('#edit-cat-description').val($link.data('description'));
				$('#edit-cat-parent').val($link.data('parent'));
				// Sync the picker selection inside the edit modal to the row's saved icon.
				(function () {
					var savedIcon = String($link.data('icon') || '');
					$('input[name="icon"]', '#jetonomy-edit-category-modal').each(function () {
						var match = this.value === savedIcon;
						this.checked = match;
						$(this).closest('.jt-icon-option').toggleClass('is-selected', match);
					});
				})();
				$('#edit-cat-visibility').val($link.data('visibility'));

				// Re-initialize color picker in modal
				var $color = $('#edit-cat-color');
				if ($color.closest('.wp-picker-container').length) {
					$color.wpColorPicker('color', $link.data('color') || '');
				} else {
					$color.val($link.data('color') || '');
					$color.wpColorPicker();
				}

				$('#jetonomy-edit-category-modal').show();
			});

			// Update Category
			$(document).on('click', '#jetonomy-update-category', function() {
				var $btn = $(this);
				var id = $('#edit-cat-id').val();
				var name = $('#edit-cat-name').val().trim();
				if (!name) {
					$('#edit-cat-name').focus();
					return;
				}

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_update_category', {
					id: id,
					name: name,
					slug: $('#edit-cat-slug').val(),
					description: $('#edit-cat-description').val(),
					parent_id: $('#edit-cat-parent').val(),
					icon: ($('input[name="icon"]', '#jetonomy-edit-category-modal').filter(':checked').val() || ''),
					color: $('#edit-cat-color').val(),
					visibility: $('#edit-cat-visibility').val()
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						location.reload();
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// Delete Category
			$(document).on('click', '.jetonomy-delete-category', function(e) {
				e.preventDefault();
				var $row = $(this).closest('tr');
				var id = $row.data('id');

				self.confirmAsync(self.i18n.confirmDelete, { danger: true }).then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_delete_category', { id: id }).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.fadeOut(300, function() { $(this).remove(); });
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					}).fail(function() {
						self.toast(self.i18n.error, 'error');
					});
				});
			});

			// Drag-sort Categories
			if ($('#jetonomy-categories-list').length) {
				$('#jetonomy-categories-list').sortable({
					handle: '.jetonomy-drag-handle',
					placeholder: 'ui-sortable-placeholder',
					update: function() {
						var order = [];
						// Parent rows only. Children render inline on their parent's
						// page, so counting them would make the batch larger than
						// per_page and its tail would overwrite the next page's
						// positions - the same corruption this handler was fixed for,
						// reachable from page 1 (Basecamp 10210539659).
						$('#jetonomy-categories-list tr[data-id]').not('.jetonomy-category-child').each(function() {
							order.push($(this).data('id'));
						});
						// Only the rendered page is submitted, so the handler needs the
						// page context to turn these into absolute positions. Without it
						// page 2 renumbers from 0 and collides with page 1.
						var ctx = self.listPageContext();
						self.ajax('jetonomy_reorder_categories', {
							order: order,
							paged: ctx.paged,
							per_page: ctx.perPage
						}).done(function(res) {
							if (res.success) {
								self.toast(res.data.message);
							}
						});
					}
				});
			}

			// Close Modal
			$(document).on('click', '.jetonomy-modal-close, .jetonomy-modal__overlay', function() {
				$(this).closest('.jetonomy-modal').hide();
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Spaces
		// ═══════════════════════════════════════════════════════════

		bindSpaceActions: function() {
			var self = this;

			// Drag-sort Spaces. Only rendered when one category is filtered —
			// manual order is per-category, matching Space::list_by_category().
			if ($('#jetonomy-spaces-list').length && $('#jetonomy-spaces-list .jetonomy-drag-handle').length) {
				$('#jetonomy-spaces-list').sortable({
					handle: '.jetonomy-drag-handle',
					placeholder: 'ui-sortable-placeholder',
					update: function() {
						var order = [];
						$('#jetonomy-spaces-list tr[data-id]').each(function() {
							order.push($(this).data('id'));
						});
						var ctx = self.listPageContext();
						self.ajax('jetonomy_reorder_spaces', {
							order: order,
							paged: ctx.paged,
							per_page: ctx.perPage
						}).done(function(res) {
							if (res.success) {
								self.toast(res.data.message);
							}
						});
					}
				});
			}

			// Visibility ↔ Join Policy coupling: hidden spaces must be
			// invite-only. Server-side validation rejects the bad combo;
			// this handler stops the user from tripping the rejection in
			// the first place by snapping the dropdown when Hidden is
			// chosen. Reverse direction (changing join_policy away from
			// invite while Hidden) flips visibility back to Private with
			// an inline note so the user understands what happened.
			var coupleVisibilityJoinPolicy = function() {
				var $vis  = $('#space-visibility');
				var $join = $('#space-join-policy');
				if (!$vis.length || !$join.length) {
					return;
				}
				var noteId = 'space-visibility-coupling-note';
				var ensureNote = function(message) {
					var $note = $('#' + noteId);
					if (!$note.length) {
						$note = $('<p id="' + noteId + '" class="description jt-admin-msg jt-admin-msg--info" role="status" aria-live="polite"></p>');
						$join.after($note);
					}
					$note.text(message).show();
					setTimeout(function() { $note.fadeOut(2400); }, 4000);
				};
				$vis.off('change.jtCouple').on('change.jtCouple', function() {
					if ($(this).val() === 'hidden' && $join.val() !== 'invite') {
						$join.val('invite').trigger('change.jtCoupleSilent');
						ensureNote(self.i18n.hiddenForcesInvite || 'Hidden spaces must be invite-only.');
					}
				});
				$join.off('change.jtCouple').on('change.jtCouple', function(e) {
					if (e.namespace === 'jtCoupleSilent') {
						return;
					}
					if ($vis.val() === 'hidden' && $(this).val() !== 'invite') {
						$vis.val('private');
						ensureNote(self.i18n.hiddenRequiresInvite || 'Switched visibility to Private — Hidden requires invite-only.');
					}
				});
			};
			coupleVisibilityJoinPolicy();

			// Filter Spaces
			$(document).on('click', '#jetonomy-filter-spaces', function() {
				var params = {
					page: 'jetonomy-spaces',
					category_id: $('#filter-category').val(),
					type: $('#filter-type').val(),
					status: $('#filter-status').val()
				};
				var url = new URL(window.location.href);
				Object.keys(params).forEach(function(key) {
					if (params[key]) {
						url.searchParams.set(key, params[key]);
					} else {
						url.searchParams.delete(key);
					}
				});
				window.location.href = url.toString();
			});

			// Create Space
			$(document).on('submit', '#jetonomy-new-space-form', function(e) {
				e.preventDefault();
				var $form = $(this);
				var $btn = $form.find('[type="submit"]');
				var title = $('#space-title').val().trim();
				if (!title) {
					$('#space-title').focus();
					return;
				}

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_create_space', {
					title: title,
					slug: $('#space-slug').val(),
					description: $('#space-description').val(),
					category_id: $('#space-category').val(),
					type: $('#space-type').val(),
					visibility: $('#space-visibility').val(),
					join_policy: $('#space-join-policy').val(),
					status: $('#space-status').val(),
					icon: ($('input[name="icon"]:checked').val() || ''),
					cover_image: $('#space-cover-image').val()
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						window.location.href = 'admin.php?page=jetonomy-spaces&action=edit&space_id=' + res.data.id;
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// Update Space (Edit Form)
			$(document).on('submit', '#jetonomy-edit-space-form', function(e) {
				e.preventDefault();
				var $form = $(this);
				var $btn = $form.find('[type="submit"]');
				var spaceId = $form.data('space-id');

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_update_space', {
					id: spaceId,
					title: $('#space-title').val(),
					slug: $('#space-slug').val(),
					description: $('#space-description').val(),
					category_id: $('#space-category').val(),
					type: $('#space-type').val(),
					visibility: $('#space-visibility').val(),
					join_policy: $('#space-join-policy').val(),
					status: $('#space-status').val(),
					icon: ($('input[name="icon"]:checked').val() || ''),
					cover_image: $('#space-cover-image').val()
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// Delete Space
			$(document).on('click', '.jetonomy-delete-space', function(e) {
				e.preventDefault();
				var $row = $(this).closest('tr');
				var id = $(this).data('id');
				var mode = $(this).data('mode') || 'transfer';

				// The two outcomes are not comparable, so they do not share a
				// warning. Archiving keeps every topic; purging destroys other
				// members' content too, and says so.
				var warning = mode === 'purge'
					? (self.i18n.confirmPurgeSpace || self.i18n.confirmDelete)
					: (self.i18n.confirmArchiveSpace || self.i18n.confirmDelete);

				self.confirmAsync(warning, { danger: mode === 'purge' }).then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_delete_space', { id: id, mode: mode }).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							// An archived space still exists, so the row stays and
							// is reloaded; only a purge removes it from the list.
							if (res.data && res.data.removed) {
								$row.fadeOut(300, function() { $(this).remove(); });
							} else {
								window.location.reload();
							}
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					}).fail(function() {
						self.toast(self.i18n.error, 'error');
					});
				});
			});

			// ── Topic Prefix Repeater ──
			$(document).on('change', '#ss-enable-prefixes', function() {
				$('#jt-prefixes-config').toggle(this.checked);
			});
			$(document).on('click', '#jt-add-prefix', function() {
				var labelPlaceholder = Jetonomy.i18n.prefixLabel || 'Label';
				var removeTitle      = Jetonomy.i18n.removePrefix || 'Remove';
				var row = '<div class="jt-prefix-row">' +
					'<input type="text" class="jt-prefix-name" placeholder="' + $('<div>').text(labelPlaceholder).html() + '" maxlength="50">' +
					'<input type="color" class="jt-prefix-color" value="#3B82F6">' +
					'<button type="button" class="button jt-prefix-remove" title="' + $('<div>').text(removeTitle).html() + '">&times;</button>' +
					'</div>';
				$('#jt-prefixes-list').append(row);
			});
			$(document).on('click', '.jt-prefix-remove', function() {
				$(this).closest('.jt-prefix-row').remove();
			});

			// Space Settings Form
			$(document).on('submit', '#jetonomy-space-settings-form', function(e) {
				e.preventDefault();
				var $form = $(this);
				var $btn = $form.find('[type="submit"]');
				var spaceId = $form.data('space-id');

				var settings = {};
				var whoCanPost = $('#ss-who-can-post').val();
				var whoCanReply = $('#ss-who-can-reply').val();
				var requireApproval = $('#ss-require-approval').is(':checked');
				var allowVoting = $('#ss-allow-voting').is(':checked');
				var postsPerPage = $('#ss-posts-per-page').val();

				settings.who_can_post = whoCanPost || 'members';
				settings.who_can_reply = whoCanReply || 'members';
				settings.require_approval = requireApproval ? '1' : '0';
				settings.allow_voting = allowVoting ? '1' : '0';
				// Save null when empty so Space::get_posts_per_page() can resolve via the
				// space → global → 20 fallback chain. Coercing empty to 20 here would
				// poison the per-space settings JSON and override the global default.
				var ppNum = parseInt(postsPerPage, 10);
				settings.posts_per_page = (postsPerPage === '' || isNaN(ppNum) || ppNum <= 0)
					? null
					: Math.max(1, Math.min(100, ppNum));

				// Collect topic prefixes.
				var enablePrefixes = $('#ss-enable-prefixes').is(':checked');
				settings.enable_prefixes = enablePrefixes ? '1' : '0';
				var prefixes = [];
				if (enablePrefixes) {
					$('#jt-prefixes-list .jt-prefix-row').each(function() {
						var name = $(this).find('.jt-prefix-name').val().trim();
						var color = $(this).find('.jt-prefix-color').val();
						if (name) {
							prefixes.push({ name: name, color: color });
						}
					});
				}
				settings.prefixes = prefixes;

				// BuddyPress group linking.
				var $bpGroup = $('#ss-bp-group');
				if ($bpGroup.length) {
					settings.bp_group_id = $bpGroup.val() || '';
				}

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_update_space', {
					id: spaceId,
					settings: JSON.stringify(settings)
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// ── Space Members ──

			// User search for adding members
			var searchTimeout;
			$(document).on('input', '#member-search', function() {
				var $input = $(this);
				var query = $input.val().trim();
				clearTimeout(searchTimeout);

				if (query.length < 2) {
					$('#member-search-results').hide();
					return;
				}

				searchTimeout = setTimeout(function() {
					self.ajax('jetonomy_search_users', { search: query }).done(function(res) {
						if (res.success && res.data.users.length) {
							var html = '';
							$.each(res.data.users, function(i, user) {
								html += '<div class="jetonomy-search-item" data-user-id="' + user.id + '" data-name="' + $('<span>').text(user.display_name).html() + '">';
								html += '<img src="' + user.avatar + '" alt="">';
								html += '<span>' + $('<span>').text(user.display_name).html() + ' (' + $('<span>').text(user.user_login).html() + ')</span>';
								html += '</div>';
							});
							$('#member-search-results').html(html).show();
						} else {
							$('#member-search-results').hide();
						}
					});
				}, 300);
			});

			// Select user from search results
			$(document).on('click', '.jetonomy-search-item', function() {
				var userId = $(this).data('user-id');
				var name = $(this).data('name');
				$('#member-user-id').val(userId);
				$('#member-search').val(name);
				$('#member-search-results').hide();
			});

			// Hide search results on click outside
			$(document).on('click', function(e) {
				if (!$(e.target).closest('#member-search, #member-search-results').length) {
					$('#member-search-results').hide();
				}
			});

			// Add member
			$(document).on('click', '#jetonomy-add-member', function() {
				var $btn = $(this);
				var spaceId = $btn.data('space-id');
				var userId = $('#member-user-id').val();
				var role = $('#member-role').val();

				if (!userId) {
					$('#member-search').focus();
					return;
				}

				$btn.prop('disabled', true);

				self.ajax('jetonomy_add_space_member', {
					space_id: spaceId,
					user_id: userId,
					role: role
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						location.reload();
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Change member role
			$(document).on('change', '.jetonomy-change-member-role', function() {
				var $select = $(this);
				var spaceId = $select.data('space-id');
				var userId = $select.data('user-id');
				var role = $select.val();

				self.ajax('jetonomy_change_member_role', {
					space_id: spaceId,
					user_id: userId,
					role: role
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				});
			});

			// Remove member
			$(document).on('click', '.jetonomy-remove-member', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var spaceId = $btn.data('space-id');
				var userId = $btn.data('user-id');

				self.confirmAsync(self.i18n.confirmDelete, { danger: true }).then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_remove_space_member', {
						space_id: spaceId,
						user_id: userId
					}).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.fadeOut(300, function() { $(this).remove(); });
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					});
				});
			});

			// ── Access Rules ──

			// Build adapter-specific rule type options and autocomplete.
			(function() {
				var adapters = (window.jetonomyAdmin && window.jetonomyAdmin.membershipAdapters) || [];
				var adapterMap = {};
				var $ruleType = $('#rule-type');

				// Inject adapter-specific options into the rule type dropdown.
				for (var i = 0; i < adapters.length; i++) {
					var a = adapters[i];
					adapterMap[a.id] = a.levels;
					$ruleType.append('<option value="membership:' + a.id + '">' + a.label + '</option>');
				}

				var $wrap = $('#rule-value-membership-wrap');
				var $input = $('#rule-value-membership-search');
				var $hidden = $('#rule-value-membership');
				var $results = $('#rule-value-membership-results');
				var activeLevels = [];

				function renderResults(query) {
					$results.empty();
					if (!query || query.length < 1) { $results.hide(); return; }
					var q = query.toLowerCase();
					var matches = activeLevels.filter(function(l) { return l.label.toLowerCase().indexOf(q) > -1; });

					if (!matches.length) {
						$results.append('<div class="jetonomy-ac-empty">No matches</div>');
						$results.show();
						return;
					}

					// Group by the optional 'kind'. Adapters that have not
					// adopted it render exactly as before - one flat list.
					var grouped = matches.some(function(l) { return !!l.kind; });

					if (grouped) {
						// Order kinds by first appearance in the adapter's own
						// list, not alphabetically, so the adapter decides which
						// kind an owner is most likely to want first. Sort BEFORE
						// the cap, otherwise a kind can be split across the
						// boundary and appear twice.
						var kindOrder = {};
						activeLevels.forEach(function(l) {
							if (l.kind && !(l.kind in kindOrder)) {
								kindOrder[l.kind] = Object.keys(kindOrder).length;
							}
						});
						// Rows with no kind sort LAST, never between two headed
						// groups - sitting under someone else's heading with no
						// heading of their own reads as belonging to it. Only
						// reachable on an adapter that half-adopted 'kind'.
						function rank(l) { return l.kind ? kindOrder[l.kind] : Number.MAX_SAFE_INTEGER; }
						matches = matches.slice().sort(function(a, b) { return rank(a) - rank(b); });
					}

					// The cap is on the LIST, never per group: capping per group
					// would make the "N more" count a lie and could hide a whole
					// kind without saying so.
					var shown = matches.slice(0, 20);
					var lastKind = null;

					shown.forEach(function(level) {
						if (grouped) {
							var kind = level.kind || '';
							if (kind !== lastKind) {
								lastKind = kind;
								if (kind) {
									var $head = $('<div class="jetonomy-ac-group"/>');
									$head.append($('<span class="jetonomy-ac-group__kind"/>').text(kind));
									if (level.note) {
										$head.append($('<span class="jetonomy-ac-group__note"/>').text(level.note));
									}
									$results.append($head);
								}
							}
						}
						$results.append(
							$('<div class="jetonomy-ac-item"/>').attr('data-id', level.id).text(level.label)
						);
					});

					if (matches.length > shown.length) {
						$results.append('<div class="jetonomy-ac-empty">' + (matches.length - shown.length) + ' more — refine search</div>');
					}
					$results.show();
				}

				$input.on('input', function() {
					$hidden.val('');
					renderResults($(this).val());
				});

				$results.on('click', '.jetonomy-ac-item', function() {
					$input.val($(this).text());
					$hidden.val($(this).data('id'));
					$results.hide();
				});

				$(document).on('click', function(e) {
					if (!$(e.target).closest('#rule-value-membership-wrap').length) {
						$results.hide();
					}
				});

				// Toggle between text input and adapter autocomplete.
				$(document).on('change', '#rule-type', function() {
					var val = $(this).val();
					var isAdapter = val.indexOf('membership:') === 0;
					// 'everyone' and 'logged_in' match on the type alone -
					// AccessRule ignores rule_value for both, and the handler
					// stores null. Showing a value box for them invited an
					// owner to type something that was silently discarded.
					var takesValue = !isAdapter && val !== 'everyone' && val !== 'logged_in';

					$('#rule-value').toggle(takesValue).val('');
					if (takesValue) {
						var placeholders = (self.i18n && self.i18n.rulePreview && self.i18n.rulePreview.typePlaceholders) || {};
						$('#rule-value').attr('placeholder', placeholders[val] || '');
					}
					$wrap.toggle(isAdapter);
					$input.val('');
					$hidden.val('');
					$results.hide();

					if (isAdapter) {
						var adapterId = val.replace('membership:', '');
						activeLevels = adapterMap[adapterId] || [];
						$input.attr('placeholder', adapters.filter(function(a) { return a.id === adapterId; })[0].label + '...');
					}
					renderRulePreview();
				});

				/**
				 * Plain-English sentence for the rule being composed.
				 *
				 * The two trailing selects are the confusing part of this form:
				 * "Grants" is what the person may DO, "Space Role" is what they
				 * are RECORDED as, and nothing at the point of use said so — or
				 * warned that the pair can contradict itself (Read + Admin reads
				 * as "look but don't touch, and also run the place"). Rather than
				 * explain two dials in the abstract, describe the combination
				 * the owner has actually picked, and flag it when it disagrees.
				 */
				function renderRulePreview() {
					var $out = $('[data-jt-rule-preview]');
					if (!$out.length) { return; }

					var i18n     = (self.i18n && self.i18n.rulePreview) || {};
					var typeSel  = $('#rule-type option:selected').text();
					var grants   = $('#rule-grants').val();
					var role     = $('#rule-space-role').val();
					// Roster role is derived now, so its label comes from the
					// dictionary rather than a select the owner can see.
					var roleTxt  = (i18n.roles && i18n.roles[role]) || role;
					var grantTxt = (i18n.grants && i18n.grants[grants]) || grants;

					var who = i18n.whoFallback || 'People who match this rule';
					var val = $('#rule-value').is(':visible') ? $('#rule-value').val() : $('#rule-value-membership-search').val();
					if (val) { who = typeSel + ': ' + val; }

					var sentence = (i18n.sentence || '%1$s can %2$s. They are recorded as %3$s.')
						.replace('%1$s', who)
						.replace('%2$s', grantTxt)
						.replace('%3$s', roleTxt);

					// No mismatch warning any more: the roster role is derived
					// from the access level, so the two can no longer disagree.
					var warn = '';

					// The "who matches" half. Keyed off the raw type, so an
					// adapter option ("membership:learndash") falls through to
					// no note rather than showing a built-in type's sentence.
					var typeNotes = i18n.typeNotes || {};
					var note      = typeNotes[$('#rule-type').val()] || '';

					$out.html('').append($('<span/>').text(sentence));
					if (warn) {
						$out.append($('<span class="jt-rule-preview__warn"/>').text(' ' + warn));
					}
					if (note) {
						$out.append($('<span class="jt-rule-preview__note"/>').text(note));
					}
				}

				// Roster role is derived, never chosen: read -> viewer,
				// participate -> member, full -> moderator. Matches
				// AccessRule::cap_space_role() on the server, which is the
				// authority - this only keeps the hidden field honest.
				function syncDerivedRole() {
					var map = { read: 'viewer', participate: 'member', full: 'moderator' };
					$('#rule-space-role').val(map[$('#rule-grants').val()] || 'viewer');
				}

				$(document).on('change', '#rule-grants', syncDerivedRole);
				syncDerivedRole();

				$(document).on('change', '#rule-grants, #rule-space-role', renderRulePreview);
				$(document).on('input', '#rule-value, #rule-value-membership-search', renderRulePreview);
				// Apply the type rules to whatever is selected on load, not just
				// on the first change. The form opens on 'Everyone', which takes
				// no value, so without this the owner is shown an empty value box
				// for a rule that ignores it.
				$ruleType.trigger('change');
				renderRulePreview();
			})();

			// Add rule
			$(document).on('click', '#jetonomy-add-rule', function() {
				var $btn = $(this);
				var spaceId = $btn.data('space-id');
				var ruleTypeVal = $('#rule-type').val();
				var isAdapter = ruleTypeVal.indexOf('membership:') === 0;
				var ruleType = isAdapter ? 'membership' : ruleTypeVal;
				var ruleValue = isAdapter ? $('#rule-value-membership').val() : $('#rule-value').val();

				$btn.prop('disabled', true);

				self.ajax('jetonomy_add_access_rule', {
					space_id: spaceId,
					rule_type: ruleType,
					rule_value: ruleValue,
					grants: $('#rule-grants').val(),
					space_role: $('#rule-space-role').val(),
					priority: $('#rule-priority').val()
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						location.reload();
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Sync existing memberships for a rule
			$(document).on('click', '.jetonomy-sync-rule', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text(self.i18n.syncing || 'Syncing...');

				self.ajax('jetonomy_sync_access_rule', {
					space_id: $btn.data('space-id'),
					rule_value: $btn.data('value'),
					// The server reads the rule by id and derives the roster role
					// from its grants; space_role is sent only for older builds.
					rule_id: $btn.data('id'),
					space_role: $btn.data('role')
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$btn.text((self.i18n.syncedFormat || 'Synced (%d)').replace('%d', res.data.synced));
					} else {
						self.toast(res.data || self.i18n.error, 'error');
						$btn.text(self.i18n.sync || 'Sync');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
					$btn.text(self.i18n.sync || 'Sync');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Delete rule
			$(document).on('click', '.jetonomy-delete-rule', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var id = $btn.data('id');

				self.confirmAsync(self.i18n.confirmDelete, { danger: true }).then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_delete_access_rule', { id: id }).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.fadeOut(300, function() { $(this).remove(); });
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					});
				});
			});

			// ── Join Requests ──

			// Approve join request
			$(document).on('click', '.jetonomy-approve-join-request', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var id = $btn.data('id');
				var spaceId = $btn.data('space-id');

				$btn.prop('disabled', true);

				self.ajax('jetonomy_approve_join_request', {
					id: id,
					space_id: spaceId
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$row.fadeOut(300, function() { $(this).remove(); });
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Deny join request
			$(document).on('click', '.jetonomy-deny-join-request', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var id = $btn.data('id');

				$btn.prop('disabled', true);

				self.ajax('jetonomy_deny_join_request', {
					id: id
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$row.fadeOut(300, function() { $(this).remove(); });
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Invite Links
		// ═══════════════════════════════════════════════════════════

		bindInviteLinks: function() {
			var self = this;
			var $table = $('#jetonomy-invites-table');
			if (!$table.length) {
				return;
			}
			var spaceId = $table.data('space-id');

			// Build a single <tr> for an invite row.
			function rowHtml(invite) {
				var i18n = self.i18n;
				var uses = invite.max_uses > 0
					? (invite.used_count + ' / ' + invite.max_uses)
					: (invite.used_count + ' / ' + (i18n.inviteUnlimited || 'Unlimited'));
				var expires = invite.expires_at ? invite.expires_at : (i18n.inviteNever || 'Never');
				if (!invite.is_valid) {
					expires = (i18n.inviteExpired || 'Expired');
				}
				// These rows are injected after the shell renders, so they must
				// carry the same core small-screen contract jetonomy_admin_table()
				// emits server-side (column-primary + toggle-row on the primary
				// cell, data-colname everywhere). Without it the responsive CSS
				// has nothing to collapse and the row stays a wide strip.
				var $tr = $('<tr>').attr('data-invite-id', invite.id);
				$('<td>', { 'class': 'column-link column-primary', 'data-colname': i18n.inviteLink || 'Invite Link' })
					.append($('<code>').text(invite.invite_url))
					.append($('<button>', {
						type: 'button',
						'class': 'toggle-row',
						'aria-expanded': 'false'
					}).append($('<span>', { 'class': 'screen-reader-text' }).text(i18n.showMoreDetails || 'Show more details')))
					.appendTo($tr);
				$('<td>', { 'class': 'column-uses', 'data-colname': i18n.inviteUses || 'Uses' }).text(uses).appendTo($tr);
				$('<td>', { 'class': 'column-expires', 'data-colname': i18n.inviteExpires || 'Expires' }).text(expires).appendTo($tr);
				var $actions = $('<td>', { 'class': 'column-actions', 'data-colname': i18n.actions || 'Actions' });
				$('<button>', { type: 'button', 'class': 'button button-small jetonomy-copy-invite' })
					.attr('data-url', invite.invite_url)
					.text(i18n.copy || 'Copy')
					.appendTo($actions);
				$('<button>', { type: 'button', 'class': 'button button-small button-link-delete jetonomy-revoke-invite' })
					.attr('data-id', invite.id)
					.text(' ' + (i18n.revoke || 'Revoke'))
					.appendTo($actions);
				$actions.appendTo($tr);
				return $tr;
			}

			// Load existing links for this space on tab render.
			function loadInvites() {
				self.ajax('jetonomy_list_invites', { space_id: spaceId }).done(function(res) {
					if (!res.success || !res.data || !res.data.invites || !res.data.invites.length) {
						return;
					}
					var $tbody = $table.find('tbody');
					$tbody.find('.jetonomy-empty-row').remove();
					res.data.invites.forEach(function(invite) {
						$tbody.append(rowHtml(invite));
					});
				});
			}
			loadInvites();

			// Copy a link to the clipboard.
			function copyToClipboard(text) {
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function() {
						self.toast(self.i18n.inviteCopied || 'Copied.');
					}).catch(function() {
						self.toast(self.i18n.error, 'error');
					});
					return;
				}
				var $tmp = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
				$tmp[0].select();
				try {
					document.execCommand('copy');
					self.toast(self.i18n.inviteCopied || 'Copied.');
				} catch (e) {
					self.toast(self.i18n.error, 'error');
				}
				$tmp.remove();
			}

			// Generate a new invite link.
			$(document).on('click', '#jetonomy-generate-invite', function() {
				var $btn = $(this);
				var maxUses = parseInt($('#invite-max-uses').val(), 10) || 0;
				var expiresAt = $('#invite-expires-at').val() || '';

				$btn.prop('disabled', true);

				self.ajax('jetonomy_generate_invite', {
					space_id: spaceId,
					max_uses: maxUses,
					expires_at: expiresAt
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message || self.i18n.saved);
						var $tbody = $table.find('tbody');
						$tbody.find('.jetonomy-empty-row').remove();
						$tbody.prepend(rowHtml({
							id: res.data.id,
							invite_url: res.data.invite_url,
							max_uses: res.data.max_uses,
							used_count: 0,
							expires_at: res.data.expires_at,
							is_valid: true
						}));
						copyToClipboard(res.data.invite_url);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Copy an existing link.
			$(document).on('click', '.jetonomy-copy-invite', function() {
				copyToClipboard($(this).data('url'));
			});

			// Revoke a link.
			$(document).on('click', '.jetonomy-revoke-invite', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var id = $btn.data('id');

				self.confirmAsync(self.i18n.inviteRevokeConfirm, { danger: true }).then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_revoke_invite', {
						space_id: spaceId,
						id: id
					}).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.fadeOut(300, function() {
								$(this).remove();
								if (!$table.find('tbody tr').length) {
									location.reload();
								}
							});
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					});
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Moderation
		// ═══════════════════════════════════════════════════════════

		bindModerationActions: function() {
			var self = this;

			// Approve / Spam / Trash
			$(document).on('click', '.jetonomy-moderate-btn', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var action = $btn.data('action');
				var objectType = $btn.data('type');
				var objectId = $btn.data('id');

				var ajaxAction;
				var confirmMsg = '';
				switch (action) {
					case 'approve':
						ajaxAction = 'jetonomy_approve_content';
						break;
					case 'spam':
						ajaxAction = 'jetonomy_spam_content';
						confirmMsg = self.i18n.confirmSpam;
						break;
					case 'trash':
						ajaxAction = 'jetonomy_trash_content';
						confirmMsg = self.i18n.confirmTrash;
						break;
					default:
						return;
				}

				// Spam / Trash are destructive — they pull content from the
				// community. Confirm before firing (Approve is safe, no prompt).
				var run = function() {
					$btn.prop('disabled', true);
					$row.find('.jetonomy-moderate-btn').prop('disabled', true);

					self.ajax(ajaxAction, {
						object_type: objectType,
						object_id: objectId
					}).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.addClass('jetonomy-moderated');
							setTimeout(function() {
								$row.fadeOut(300, function() { $(this).remove(); });
							}, 500);
						} else {
							self.toast(res.data || self.i18n.error, 'error');
							$row.find('.jetonomy-moderate-btn').prop('disabled', false);
						}
					}).fail(function() {
						self.toast(self.i18n.error, 'error');
						$row.find('.jetonomy-moderate-btn').prop('disabled', false);
					});
				};

				if ('' !== confirmMsg) {
					self.confirmAsync(confirmMsg, { danger: true }).then(function(ok) {
						if (ok) { run(); }
					});
				} else {
					run();
				}
			});

			// Resolve Flag
			$(document).on('click', '.jetonomy-resolve-flag', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var flagId = $btn.data('flag-id');
				var resolution = $btn.data('resolution');

				$btn.prop('disabled', true);
				$row.find('.jetonomy-resolve-flag').prop('disabled', true);

				self.ajax('jetonomy_resolve_flag', {
					flag_id: flagId,
					resolution: resolution
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$row.addClass('jetonomy-moderated');
						setTimeout(function() {
							$row.fadeOut(300, function() { $(this).remove(); });
						}, 500);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
						$row.find('.jetonomy-resolve-flag').prop('disabled', false);
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
					$row.find('.jetonomy-resolve-flag').prop('disabled', false);
				});
			});

			// Unban User
			$(document).on('click', '.jetonomy-unban-user', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var restrictionId = $btn.data('restriction-id');

				// Pass the danger tone so the OK button renders red. Unbanning
				// is destructive (the user immediately regains site access) and
				// should look like every other destructive admin confirm.
				self.confirmAsync(self.i18n.confirmUnban || self.i18n.confirmDelete, { danger: true }).then(function(ok) {
					if (!ok) return;
					$btn.prop('disabled', true);

					self.ajax('jetonomy_unban_user', {
						restriction_id: restrictionId
					}).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							// On Users page: restore Ban link. On Moderation page: remove row.
							if ($row.find('.ban').length) {
								$row.find('.ban').html(
									'<a href="#" class="jetonomy-ban-trigger" data-user-id="' +
									$row.data('user-id') + '" data-username="' +
									$row.find('strong').first().text() + '">' +
									self.i18n.ban +
									'</a> | '
								);
							} else {
								$row.fadeOut(300, function() { $(this).remove(); });
							}
						} else {
							self.toast(res.data || self.i18n.error, 'error');
							$btn.prop('disabled', false);
						}
					}).fail(function() {
						self.toast(self.i18n.error, 'error');
						$btn.prop('disabled', false);
					});
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Users
		// ═══════════════════════════════════════════════════════════

		bindUserActions: function() {
			var self = this;

			// Change Trust Level - Show Dropdown
			$(document).on('click', '.jetonomy-change-trust-trigger', function(e) {
				e.preventDefault();
				var $link = $(this);
				var userId = $link.data('user-id');
				var current = $link.data('current');

				var $dropdown = $('#jetonomy-trust-dropdown');
				$('#trust-user-id').val(userId);
				$('#trust-level-select').val(current);

				// Position near the link
				var offset = $link.offset();
				$dropdown.css({
					top: offset.top + $link.outerHeight() + 4,
					left: offset.left
				}).show();
			});

			// Save Trust Level
			$(document).on('click', '#jetonomy-save-trust', function() {
				var $btn = $(this);
				var userId = $('#trust-user-id').val();
				var trustLevel = $('#trust-level-select').val();

				$btn.prop('disabled', true);

				self.ajax('jetonomy_change_trust_level', {
					user_id: userId,
					trust_level: trustLevel
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						// Update badge in table
						var $badge = $('.jetonomy-trust-badge[data-user-id="' + userId + '"]');
						$badge.attr('class', 'jetonomy-trust-badge jetonomy-trust-badge--' + trustLevel);
						// Reload for clean label update
						location.reload();
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					$('#jetonomy-trust-dropdown').hide();
				});
			});

			// Cancel trust dropdown
			$(document).on('click', '.jetonomy-dropdown-cancel', function() {
				$(this).closest('.jetonomy-dropdown').hide();
			});

			// Close dropdown on outside click
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.jetonomy-dropdown, .jetonomy-change-trust-trigger').length) {
					$('.jetonomy-dropdown').hide();
				}
			});

			// Ban User - Show Modal
			$(document).on('click', '.jetonomy-ban-trigger', function(e) {
				e.preventDefault();
				var userId = $(this).data('user-id');
				var username = $(this).data('username');

				$('#ban-user-id').val(userId);
				$('#ban-user-label').text('Banning: ' + username);
				$('#ban-type').val('global_ban');
				$('#ban-reason').val('');
				$('#ban-duration').val('permanent');
				$('#jetonomy-ban-modal').show();
			});

			// Silence User - Show Modal with Silence preselected
			$(document).on('click', '.jetonomy-silence-trigger', function(e) {
				e.preventDefault();
				var userId = $(this).data('user-id');
				var $row = $(this).closest('tr');
				var username = $row.find('.column-username strong').text();

				$('#ban-user-id').val(userId);
				$('#ban-user-label').text('Silencing: ' + username);
				$('#ban-type').val('silence');
				$('#ban-reason').val('');
				$('#ban-duration').val('7d');
				$('#jetonomy-ban-modal').show();
			});

			// Confirm Ban
			$(document).on('click', '#jetonomy-confirm-ban', function() {
				var $btn = $(this);
				var userId = $('#ban-user-id').val();
				if (!userId) return;

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_ban_user', {
					user_id: userId,
					type: $('#ban-type').val(),
					reason: $('#ban-reason').val(),
					duration: $('#ban-duration').val()
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$('#jetonomy-ban-modal').hide();
						// Toggle row action: Ban → Unban
						var $row = $('tr[data-user-id="' + userId + '"]');
						$row.find('.ban').html(
							'<a href="#" class="jetonomy-unban-user" data-restriction-id="' + res.data.restriction_id + '">' +
							self.i18n.unban +
							'</a> | '
						);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Import
		// ═══════════════════════════════════════════════════════════

		bindImport: function() {
			var self = this;

			// Fresh import
			$(document).on('click', '.jetonomy-import-btn', function() {
				self.startImport($(this).data('source'), 'forums', 0, true);
			});

			// Resume interrupted import — continues prior state, so NOT a new run.
			$(document).on('click', '.jetonomy-import-resume-btn', function() {
				var $btn = $(this);
				self.startImport($btn.data('source'), $btn.data('phase'), parseInt($btn.data('offset'), 10), false);
			});

			// Start over — overwrite resume state then start fresh from beginning
			$(document).on('click', '.jetonomy-import-restart-btn', function() {
				var $btn = $(this);
				self.confirmAsync(
					self.i18n.importRestartConfirm || 'This will discard the interrupted import progress. Continue?',
					{ danger: true, title: self.i18n.importRestartTitle || 'Restart import' }
				).then(function(ok) {
					if (!ok) return;
					self.startImport($btn.data('source'), 'forums', 0, true);
				});
			});
		},

		startImport: function(source, startPhase, startOffset, isNewRun) {
			var self = this;
			var card = document.getElementById('import-source-' + source);
			if (!card) return;

			var progress     = card.querySelector('.jetonomy-import-progress');
			var progressFill = progress.querySelector('.jetonomy-progress-bar__fill');
			var statusText   = progress.querySelector('.jetonomy-import-status-text');
			var statusPct    = progress.querySelector('.jetonomy-import-status-percent');
			var results      = card.querySelector('.jetonomy-import-results');
			var actionDiv    = card.querySelector('.jetonomy-import-action');
			var steps        = progress.querySelectorAll('.jetonomy-step');

			// Hide action buttons, show progress UI
			actionDiv.style.display  = 'none';
			progress.style.display   = 'block';
			results.style.display    = 'none';
			progress.classList.remove('jetonomy-import-progress--done');
			progressFill.style.width = '0%';
			statusPct.textContent    = '0%';

			function updateStepIndicator(phase) {
				var found = false;
				// Iterate in reverse so steps before the active one get marked done
				for (var i = steps.length - 1; i >= 0; i--) {
					var s = steps[i];
					s.classList.remove('jetonomy-step--active', 'jetonomy-step--done');
					if (s.dataset.step === phase) {
						s.classList.add('jetonomy-step--active');
						found = true;
					} else if (found) {
						s.classList.add('jetonomy-step--done');
					}
				}
			}

			function buildCompleteNotice(processed, skipped) {
				skipped = parseInt(skipped, 10) || 0;
				var notice = document.createElement('div');
				// A partial success is a warning, not a clean success — the site owner
				// needs to know some files did not come across rather than seeing a
				// green tick and assuming everything migrated.
				notice.className = skipped > 0 ? 'notice notice-warning' : 'notice notice-success';
				var p = document.createElement('p');
				var strong = document.createElement('strong');
				strong.textContent = (Jetonomy.i18n.importDone || 'Import complete!') + ' ';
				p.appendChild(strong);
				p.appendChild(document.createTextNode(processed + ' records imported successfully. '));
				if (skipped > 0) {
					var warn = document.createElement('strong');
					var tmpl = Jetonomy.i18n.importSkippedFiles || '%d file(s) could not be recovered and were left linked in the original post text.';
					warn.textContent = tmpl.replace('%d', skipped) + ' ';
					p.appendChild(warn);
				}
				var link = document.createElement('a');
				link.href = '';
				link.textContent = Jetonomy.i18n.reloadPage || 'Reload page';
				p.appendChild(link);
				p.appendChild(document.createTextNode(' to see updated status.'));
				notice.appendChild(p);
				return notice;
			}

			// Only the very first batch of a fresh/restart run signals new_run so the
			// server clears prior state once — recursive continuations must not, or
			// they would wipe the id_map the run depends on (and wpForo's per-board
			// hand-off also arrives as forums/0).
			function runBatch(phase, offset, newRun) {
				updateStepIndicator(phase);

				var data = new FormData();
				data.append('action',     'jetonomy_import_batch');
				data.append('nonce',      self.nonce);
				data.append('source',     source);
				data.append('phase',      phase);
				data.append('offset',     offset);
				data.append('batch_size', 500);
				data.append('new_run',    newRun ? 1 : 0);

				fetch(self.ajaxUrl, { method: 'POST', body: data })
					.then(function(r) { return r.json(); })
					.then(function(res) {
						if (!res.success) {
							var errFmt    = Jetonomy.i18n.importErrorFormat || 'Error: %s';
							var errDetail = res.data || (Jetonomy.i18n.importErrorUnknown || 'Unknown error');
							statusText.textContent  = errFmt.replace('%s', errDetail);
							actionDiv.style.display = 'block';
							return;
						}

						var d = res.data;
						progressFill.style.width = d.percent + '%';
						statusText.textContent   = d.message;
						statusPct.textContent    = d.percent + '%';

						if (!d.done) {
							runBatch(d.phase, d.offset, false);
						} else {
							// Mark complete
							progressFill.style.width = '100%';
							statusPct.textContent    = '100%';
							progress.classList.add('jetonomy-import-progress--done');
							statusText.textContent   = Jetonomy.i18n.importDone || 'Import complete!';

							steps.forEach(function(s) {
								s.classList.remove('jetonomy-step--active');
								s.classList.add('jetonomy-step--done');
							});

							results.style.display = 'block';
							while (results.firstChild) { results.removeChild(results.firstChild); }
							results.appendChild(buildCompleteNotice(d.processed, d.skipped));

							// Only auto-reload a CLEAN import. The reload exists to reveal the
							// "Previously Imported" state, which is fine when there is nothing
							// to read — but when files were skipped it used to pull the page out
							// from under the one notice telling you so, three seconds in. The
							// skipped count and the per-item reasons are now recorded durably in
							// the import history and rendered on this page (see
							// admin/views/import.php), so nothing is lost either way; this just
							// stops us interrupting someone mid-sentence about their own data.
							if (!d.skipped) {
								setTimeout(function() { window.location.reload(); }, 3000);
							}
						}
					})
					.catch(function() {
						statusText.textContent  = Jetonomy.i18n.importConnectionLost || 'Connection lost. You can resume this import later.';
						actionDiv.style.display = 'block';
					});
			}

			runBatch(startPhase, startOffset, !!isNewRun);
		},

		// ═══════════════════════════════════════════════════════════
		//  Settings
		// ═══════════════════════════════════════════════════════════

		bindSettings: function() {
			var self = this;

			// Test Email
			$(document).on('click', '#jetonomy-test-email', function() {
				var $btn = $(this);
				var $status = $('.jetonomy-test-email-status');

				$btn.prop('disabled', true);
				$status.text(self.i18n.saving);

				self.ajax('jetonomy_test_email').done(function(res) {
					if (res.success) {
						$status.text(res.data.message).removeClass('jt-admin-msg--error').addClass('jt-admin-msg--success');
						self.toast(res.data.message);
					} else {
						$status.text(res.data || self.i18n.error).removeClass('jt-admin-msg--success').addClass('jt-admin-msg--error');
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					$status.text(self.i18n.error).removeClass('jt-admin-msg--success').addClass('jt-admin-msg--error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});
		}
	};

	$(document).ready(function() {
		Jetonomy.init();
	});

	// Small-screen row expander: core's common.js toggles tr.is-expanded but
	// never manages aria-expanded, so screen readers heard a stateless
	// button (QA 2026-07-30, card 10146443346). Delegated so it covers every
	// jetonomy_admin_table() on the page, including AJAX-refreshed rows.
	// requestAnimationFrame lets core's own toggle land first, then the
	// button mirrors the row's real state.
	//
	// Initial state: WP_List_Table screens (Activity, Revisions) print
	// core's OWN toggle markup, which ships without aria-expanded - stamp
	// it on load so the button is never stateless before first interaction
	// (QA wave-5).
	function stampToggleState(root) {
		(root || document).querySelectorAll('.jetonomy-admin .toggle-row:not([aria-expanded])').forEach(function (btn) {
			var tr = btn.closest('tr');
			btn.setAttribute('aria-expanded', tr && tr.classList.contains('is-expanded') ? 'true' : 'false');
		});
	}
	$(document).ready(function () { stampToggleState(); });
	$(document).ajaxComplete(function () { stampToggleState(); });

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.jetonomy-admin .toggle-row') : null;
		if (!btn) { return; }
		window.requestAnimationFrame(function () {
			var tr = btn.closest('tr');
			btn.setAttribute('aria-expanded', tr && tr.classList.contains('is-expanded') ? 'true' : 'false');
		});
	});

})(jQuery);

/**
 * Accessible-dialog enhancer (QA card 10150582851).
 *
 * The admin's hand-rolled overlays (Edit Category, Ban, Pro Badge Award)
 * showed/hid a positioned div with none of the dialog contract. Rather
 * than rewrite each caller, a MutationObserver watches every overlay's
 * display state and applies the ONE primitive on open: role=dialog +
 * aria-modal + labelled title, initial focus (Close first), trapped Tab
 * order, Escape closes, opener focus restored on close. Callers keep
 * their own show()/hide() logic untouched.
 */
( function () {
	var active = null;

	function focusables( root ) {
		return Array.prototype.filter.call(
			root.querySelectorAll( 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' ),
			function ( el ) { return el.offsetParent !== null; }
		);
	}

	function onKey( e ) {
		if ( ! active ) { return; }
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			active.el.style.display = 'none'; // observer runs teardown()
			return;
		}
		if ( e.key !== 'Tab' ) { return; }
		var f = focusables( active.el );
		if ( ! f.length ) { e.preventDefault(); return; }
		var first = f[ 0 ], last = f[ f.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
		else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
	}

	function boxOf( el ) {
		return el.querySelector( '.jetonomy-modal__content' ) ||
			( el.children.length === 1 ? el.children[ 0 ] : el.children[ el.children.length - 1 ] ) || el;
	}

	function enhance( el ) {
		var box = boxOf( el );
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		if ( ! box.getAttribute( 'aria-labelledby' ) ) {
			var h = box.querySelector( 'h1, h2, h3' );
			if ( h ) {
				if ( ! h.id ) { h.id = 'jt-dialog-title-' + Math.random().toString( 36 ).slice( 2, 8 ); }
				box.setAttribute( 'aria-labelledby', h.id );
			}
		}
		// Opener: prefer whatever the user actually activated. A dialog opened
		// from an async callback (the email preview waits on its AJAX) enhances
		// long after the click, by which point document.activeElement is <body>
		// - so Escape restored focus to nothing (Basecamp 10146440810, a11y
		// addendum). lastActivated is the control the user pressed, tracked
		// below, and is only fallen back on when activeElement is not useful.
		var opener = document.activeElement;
		if ( ! opener || opener === document.body || opener === document.documentElement ) {
			opener = lastActivated;
		}
		active = { el: el, opener: opener };
		document.addEventListener( 'keydown', onKey, true );
		var target = el.querySelector( '.jetonomy-modal-close' ) || focusables( el )[ 0 ];
		if ( target ) { target.focus(); }
	}

	// The control the user last activated, used as a dialog's opener when the
	// dialog is created asynchronously and focus has already gone back to the
	// body. Capture phase so it is recorded even when a handler stops
	// propagation.
	var lastActivated = null;
	document.addEventListener( 'mousedown', function ( e ) {
		var el = e.target && e.target.closest ? e.target.closest( 'button, a[href], [role="button"], input[type="submit"], input[type="button"]' ) : null;
		if ( el ) { lastActivated = el; }
	}, true );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' && e.key !== ' ' ) { return; }
		var el = e.target && e.target.closest ? e.target.closest( 'button, a[href], [role="button"]' ) : null;
		if ( el ) { lastActivated = el; }
	}, true );

	function teardown( el ) {
		if ( ! active || active.el !== el ) { return; }
		var opener = active.opener;
		active = null;
		document.removeEventListener( 'keydown', onKey, true );
		if ( opener && opener.focus ) { opener.focus(); }
	}

	function watch( el ) {
		// jQuery's .show() clears the inline value to '' and lets the
		// stylesheet default apply, so open-ness must be read from the
		// COMPUTED display, never the style attribute.
		var wasOpen = getComputedStyle( el ).display !== 'none';
		new MutationObserver( function () {
			var isOpen = getComputedStyle( el ).display !== 'none';
			if ( isOpen && ! wasOpen ) { enhance( el ); }
			if ( ! isOpen && wasOpen ) { teardown( el ); }
			wasOpen = isOpen;
		} ).observe( el, { attributes: true, attributeFilter: [ 'style', 'class' ] } );
	}

	function init() {
		document.querySelectorAll( '.jetonomy-modal, #jetonomy-award-modal' ).forEach( watch );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
