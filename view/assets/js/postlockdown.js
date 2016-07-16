/*!
 jquery-plmultiselect v1.0.2
 A jQuery multi select plugin for WordPress admin
 (c) 2015 Andy Palmer
 license: http://www.gnu.org/licenses/gpl-2.0.html
 */
(function( $, window ) {

	'use strict';

	var pluginName = 'plmultiselect';

	function Plugin( element, options ) {
		this.$el = $( element );
		this.options = $.extend( {}, options );

		this.$search = this.options.inputSearch;
		this.$left = this.options.ulAvailable;
		this.$right = this.options.ulSelected;
		this.$spinner = this.options.spinner;

		this.doingAjax = false;

		this.init();
	}

	Plugin.prototype = {
		/**
		 * Initialises the plugin instance.
		 */
		init: function() {
			var self = this;

			this.$right.height( this.$left.parent().height() );

			this.$search.autocomplete( {
				minLength: 0,
				source: function( request ) {
					self.queryItems( request.term, 0, function( json ) {
						self.renderMenu( self.$left, json.data );
					} );
				},
				create: function() {
					$( this ).autocomplete( 'search' );
				}

			} );

			// Attach the click handler for available items
			this.$left.on( 'click', '> li', function() {
				if ( !$( this ).is( '.selected' ) ) {
					self.selectItem.call( self, $( this ) );
				}

			} );

			// Attach the click handler for remove buttons on selected items.
			this.$right.on( 'click', '> li .dashicons-no', function() {
				var ID = $( this ).closest( 'li' ).data( 'ID' );
				$( this ).closest( 'li' ).remove();
				self.$left.find( '.post-' + ID ).removeClass( 'selected' );
			} );

			// If we have a list of items add them to the selected menu.
			if ( this.options.selected.length ) {
				this.selectItem( this.options.selected );
			}

			// Paginate scrolling of the available items menu.
			this.$left.on( 'scroll', function() {
				// Go to next page if the scrollbar is 15px or less from the bottom.
				if ( this.scrollHeight - $( this ).scrollTop() - 15 <= $( this ).height() ) {
					self.nextPage();
				}
			} );
		},
		nextPage: function() {
			var self = this,
				term = this.options.inputSearch.val(),
				offset = this.$left.children( 'li' ).length;

			this.queryItems( term, offset, function( json ) {
				self.renderMenu( self.$left, json.data, '', true );
			} );

		},
		/**
		 * Makes an AJAX request for a list of items.
		 * @param {string} term - The term to query items for.
		 * @param {number} offset - The number of items to pass over.
		 * @param {function} success - If the request is successful this function will be invoked.
		 */
		queryItems: function( term, offset, success ) {
			// Only allow one AJAX request at a time
			if ( this.doingAjax ) {
				return false;
			}

			this.doingAjax = true;

			this.$spinner.addClass( 'is-active' );

			$.ajax( {
				url: window.ajaxurl,
				type: 'GET',
				data: {
					action: this.options.ajaxAction,
					term: term,
					offset: offset
				},
				context: this,
				success: function( json ) {
					if ( $.isFunction( success ) ) {
						success.call( this, json );
					}
				},
				complete: function() {
					this.doingAjax = false;
					this.$spinner.removeClass( 'is-active' );
				}
			} );
		},
		/**
		 * Selects an item by cloning it, or adds an array of plain objects, to the right hand box.
		 * @param {jQuery||object[]} arg - The jQuery object or an array of plain objects.
		 */
		selectItem: function( arg ) {
			var inputName = this.options.inputName;

			if ( $.isArray( arg ) ) {
				this.renderMenu( this.$right, arg, true );
			} else {
				inputName += '[' + arg.data( 'ID' ) + ']';

				arg.clone( false )
				   .data( 'ID', arg.data( 'ID' ) )
				   .appendTo( this.$right )
				   .find( 'input.post-id' ).prop( 'disabled', false );
				//.find( 'input.post-id' ).attr( 'name', inputName );

				arg.addClass( 'selected' );
			}
		},
		/**
		 * Fills a menu with a list of items.
		 * @param {jQuery} menu - The menu to fill.
		 * @param {array} items - List of items to add to the menu.
		 * @param {bool} append - Whether to append to the menu. Setting to false clears the menu's innerHTML first.
		 */
		renderMenu: function( menu, items, append ) {
			var self = this,
				$items = [];

			if ( !append ) {
				menu.empty();
			}

			$.each( items, function( i, item ) {
				$items.push( self.getItemTpl( item ) );
			} );

			menu.append( $items );
		},
		/**
		 * Returns a jQuery object of an item to be appended to a menu.
		 * @param {object} item       The item to build a jQuery object with.
		 * @returns {jquery} The item as a jQuery object.
		 */
		getItemTpl: function( item ) {
			var $item = $( this.interpolate( this.options.itemTpl, {
				item: item,
				self: this
			} ) );

			if ( this.$right.find( '.post-' + item.ID ).length ) {
				$item.addClass( 'selected' );
			}

			return $item;
		},

		interpolate: function( template, data ) {
			return template.replace( /\{\{([^\{\}]+)\}\}/g, function( match, capture ) {
				var parts = capture.split( '.' );

				if ( parts.length > 1 ) {
					var tmpData = data;
					for ( var i = 0; i < parts.length; i++ ) {
						if ( !tmpData[ parts[ i ] ] ) {
							break;
						}

						tmpData = tmpData[ parts[ i ] ];
					}

					if ( typeof tmpData === 'string' || typeof tmpData === 'number' ) {
						return tmpData;
					}
				}

				if ( typeof data[ capture ] === 'undefined' ) {
					return '';
				}

				return data[ capture ];
			} );
		}

	};

	$.fn[ pluginName ] = function( options ) {
		return this.each( function() {
			var plugin = $( this ).data( pluginName + '.plugin' );
			if ( !plugin ) {
				plugin = new Plugin( this, options );
				$( this ).data( pluginName + '.plugin', plugin );
			}

		} );
	};

})( jQuery, window );

(function( $ ) {
	'use strict';

	$( function() {
		$( '.pl-posts-container' ).each( function() {

			var selectedKey = $( this ).find( '.pl-posts-selected .pl-multiselect' ).data( 'key' ),
				inputName = $( this ).find( '.pl-posts-selected .pl-multiselect' ).data( 'input_name' );

			$( this ).plmultiselect( {
				ajaxAction: 'pl_autocomplete',
				inputName: inputName,
				inputSearch: $( this ).find( '.pl-autocomplete' ),
				ulAvailable: $( this ).find( '.pl-posts-available .pl-multiselect' ),
				ulSelected: $( this ).find( '.pl-posts-selected .pl-multiselect' ),
				selected: window.postlockdown[ selectedKey ] || [],
				spinner: $( this ).find( '.spinner' ),
				itemTpl: $( '#plmultiselect-item-template' ).html()
			} );
		} );

	} );
})( jQuery );

//# sourceMappingURL=postlockdown.js.map