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
			this.bindModerationActions();
			this.bindUserActions();
			this.bindImport();
			this.bindSettings();
			this.initColorPickers();
			this.initCodeEditor();
			this.initMediaUploaders();
			this.bindSlugGeneration();
		},

		// ── AJAX Helper ──

		ajax: function(action, data) {
			data = data || {};
			data.action = action;
			data.nonce = this.nonce;
			return $.post(this.ajaxUrl, data);
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
					icon: $('#cat-icon').val(),
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
				$('#edit-cat-icon').val($link.data('icon'));
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
					icon: $('#edit-cat-icon').val(),
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
				if (!confirm(self.i18n.confirmDelete)) return;

				var $row = $(this).closest('tr');
				var id = $row.data('id');

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

			// Drag-sort Categories
			if ($('#jetonomy-categories-list').length) {
				$('#jetonomy-categories-list').sortable({
					handle: '.jetonomy-drag-handle',
					placeholder: 'ui-sortable-placeholder',
					update: function() {
						var order = [];
						$('#jetonomy-categories-list tr[data-id]').each(function() {
							order.push($(this).data('id'));
						});
						self.ajax('jetonomy_reorder_categories', { order: order }).done(function(res) {
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
					icon: $('#space-icon').val(),
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
					icon: $('#space-icon').val(),
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
				if (!confirm(self.i18n.confirmDelete)) return;

				var $row = $(this).closest('tr');
				var id = $(this).data('id');

				self.ajax('jetonomy_delete_space', { id: id }).done(function(res) {
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

				if (whoCanPost) settings.who_can_post = whoCanPost;
				if (whoCanReply) settings.who_can_reply = whoCanReply;
				if (requireApproval) settings.require_approval = '1';
				if (allowVoting) settings.allow_voting = '1';
				if (postsPerPage) settings.posts_per_page = postsPerPage;

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
				if (!confirm(self.i18n.confirmDelete)) return;

				var $btn = $(this);
				var $row = $btn.closest('tr');
				var spaceId = $btn.data('space-id');
				var userId = $btn.data('user-id');

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

			// ── Access Rules ──

			// Add rule
			$(document).on('click', '#jetonomy-add-rule', function() {
				var $btn = $(this);
				var spaceId = $btn.data('space-id');

				$btn.prop('disabled', true);

				self.ajax('jetonomy_add_access_rule', {
					space_id: spaceId,
					rule_type: $('#rule-type').val(),
					rule_value: $('#rule-value').val(),
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

			// Delete rule
			$(document).on('click', '.jetonomy-delete-rule', function() {
				if (!confirm(self.i18n.confirmDelete)) return;

				var $btn = $(this);
				var $row = $btn.closest('tr');
				var id = $btn.data('id');

				self.ajax('jetonomy_delete_access_rule', { id: id }).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$row.fadeOut(300, function() { $(this).remove(); });
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
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
				switch (action) {
					case 'approve':
						ajaxAction = 'jetonomy_approve_content';
						break;
					case 'spam':
						ajaxAction = 'jetonomy_spam_content';
						break;
					case 'trash':
						ajaxAction = 'jetonomy_trash_content';
						break;
					default:
						return;
				}

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
				if (!confirm(self.i18n.confirmDelete)) return;

				var $btn = $(this);
				var $row = $btn.closest('tr');
				var restrictionId = $btn.data('restriction-id');

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
				self.startImport($(this).data('source'), 'forums', 0);
			});

			// Resume interrupted import
			$(document).on('click', '.jetonomy-import-resume-btn', function() {
				var $btn = $(this);
				self.startImport($btn.data('source'), $btn.data('phase'), parseInt($btn.data('offset'), 10));
			});

			// Start over — overwrite resume state then start fresh from beginning
			$(document).on('click', '.jetonomy-import-restart-btn', function() {
				if (!confirm('This will discard the interrupted import progress. Continue?')) return;
				self.startImport($(this).data('source'), 'forums', 0);
			});
		},

		startImport: function(source, startPhase, startOffset) {
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

			function buildCompleteNotice(processed) {
				var notice = document.createElement('div');
				notice.className = 'notice notice-success';
				var p = document.createElement('p');
				var strong = document.createElement('strong');
				strong.textContent = 'Import complete! ';
				p.appendChild(strong);
				p.appendChild(document.createTextNode(processed + ' records imported successfully. '));
				var link = document.createElement('a');
				link.href = '';
				link.textContent = 'Reload page';
				p.appendChild(link);
				p.appendChild(document.createTextNode(' to see updated status.'));
				notice.appendChild(p);
				return notice;
			}

			function runBatch(phase, offset) {
				updateStepIndicator(phase);

				var data = new FormData();
				data.append('action',     'jetonomy_import_batch');
				data.append('nonce',      self.nonce);
				data.append('source',     source);
				data.append('phase',      phase);
				data.append('offset',     offset);
				data.append('batch_size', 500);

				fetch(self.ajaxUrl, { method: 'POST', body: data })
					.then(function(r) { return r.json(); })
					.then(function(res) {
						if (!res.success) {
							statusText.textContent  = 'Error: ' + (res.data || 'Unknown error');
							actionDiv.style.display = 'block';
							return;
						}

						var d = res.data;
						progressFill.style.width = d.percent + '%';
						statusText.textContent   = d.message;
						statusPct.textContent    = d.percent + '%';

						if (!d.done) {
							runBatch(d.phase, d.offset);
						} else {
							// Mark complete
							progressFill.style.width = '100%';
							statusPct.textContent    = '100%';
							progress.classList.add('jetonomy-import-progress--done');
							statusText.textContent   = 'Import complete!';

							steps.forEach(function(s) {
								s.classList.remove('jetonomy-step--active');
								s.classList.add('jetonomy-step--done');
							});

							results.style.display = 'block';
							while (results.firstChild) { results.removeChild(results.firstChild); }
							results.appendChild(buildCompleteNotice(d.processed));

							// Auto-reload after 3 seconds to show "Previously Imported" state
							setTimeout(function() { window.location.reload(); }, 3000);
						}
					})
					.catch(function() {
						statusText.textContent  = 'Connection lost. You can resume this import later.';
						actionDiv.style.display = 'block';
					});
			}

			runBatch(startPhase, startOffset);
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
						$status.text(res.data.message).css('color', '#00a32a');
						self.toast(res.data.message);
					} else {
						$status.text(res.data || self.i18n.error).css('color', '#d63638');
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					$status.text(self.i18n.error).css('color', '#d63638');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});
		}
	};

	$(document).ready(function() {
		Jetonomy.init();
	});

})(jQuery);
