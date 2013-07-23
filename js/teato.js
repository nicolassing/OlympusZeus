/*
 * jQuery Tea Theme Options
 *
 * Copyright 2013 Take a Tea (http://takeatea.com)
 *
 * Dual licensed under the MIT or GPL Version 2 licenses
 *
*/
;(function ($) {
    $(document).ready(function () {
        //Usefull vars
        var file_frame;
        var _wpcolor = jQuery().wpColorPicker ? true : false;
        var _defcolor = '000000';
        var _delcolor = 'ffaaaa';

        //Checkbox & Image input
        $.each($('.inside input[type="checkbox"]'), function (index,elem) {
            var $self = $(this);

            //Bind the change event
            $self.bind('change', function (e) {
                $self.is(':checked') ? $self.closest('label').addClass('selected') : $self.closest('label').removeClass('selected');
            });
        });

        //Checkbox check all
        $.each($('.checkboxes .checkall input[type="checkbox"]'), function (index,elem) {
            var $self = $(this);
            var $checks = $self.closest('.checkboxes').find('.inside input[type="checkbox"]');

            //Bind the change event
            $self.bind('change', function (e) {
                $checks.attr('checked', $self.is(':checked'));
                $self.is(':checked') ? $checks.closest('label').addClass('selected') : $checks.closest('label').removeClass('selected');
            });
        });

        //Color input
        $.each($('.inside .color-picker'), function (index,elem) {
            var $self = $(this);

            //Wordpress version < 3.5
            if (!_wpcolor) {
                //Use functions plugin
                $self.miniColors({
                    readonly: true,
                    change: function (hex,rgb) {
                        $self.val('' + hex);
                        $self.css('color', hex);
                    }
                });
            }
            //Wordpress version >= 3.5
            else {
                //Use functions plugin
                $self.wpColorPicker({
                    change: function (event,ui) {
                        $self.val($self.wpColorPicker('color'));
                    },
                    clear: function() {
                        $self.val('NONE');
                    }
                });
            }
        });

        //Features input
        $.each($('.features-list li'), function (index,elem) {
            var $self = $(this);
            var $link = $self.find('a');

            //Check if link
            if (!$link.length)
            {
                return;
            }

            //Get infos
            var $code = $self.find('pre');

            //Bind the click event
            $self.live('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                $self.teamodal({
                    title: $self.find('h4').text(),
                    content: [
                        {
                            label: $self.find('p').html(),
                            type: 'html',
                            code: $code.html()
                        }
                    ],
                    submitbutton: false
                });
            });
        });

        //Radio & Image input
        $.each($('.inside input[type="radio"]'), function (index,elem) {
            var $self = $(this);

            //Bind the change event
            $self.bind('change', function (e) {
                $self.closest('.inside').find('.selected').removeClass('selected');
                $self.closest('label').addClass('selected');
            });
        });

        //Range input
        $.each($('.inside input[type="range"]'), function (index,elem) {
            var $self = $(this);
            var $target = $('#' + this.id + '_output');

            $self.bind('change', function (e) {
                $target.text($self.val());
            });
        });

        //Upload input: Wordpress version < 3.5
        $.each($('.upload a.add_media.thickbox'), function (index,elem) {
            var $self = $(this);
            var _id = $self.attr('data-editor');

            //Set the upload button ID
            $self.attr('id', '' + _id + this.id);

            //Bind the click event
            $self.bind('click', function (e) {
                formfield = _id;
                tb_show('', 'media-upload.php?type=image&amp;TB_iframe=true&amp;post_id=0');
                return false;
            });

            //Override the default submit function to get the image details
            window.send_to_editor = function (html) {
                //Get the image details
                imgurl = undefined == html.src ? html.href : html.src;
                $('#' + _id).val(imgurl);
                //Delete popin
                tb_remove();
            }
        });

        //Upload input: Wordpress version >= 3.5
        $.each($('.upload a.add_media:not(.thickbox)'), function (index,elem) {
            var $self = $(this);

            //Check if parent has the "customized" css class from Tea TO
            if (!$self.parent().hasClass('customized')) {
                return false;
            }

            //Get parent and target jQuery elements
            var $parent = $self.closest('.customized');
            var $result = $parent.parent().find('.upload_image_result');
            var $target = $('#' + $parent.attr('data-target'));

            //Set default vars
            var _wpid = wp.media.model.settings.post.id;
            var _delete = $self.closest('.upload').attr('data-del');
            var _title = $parent.attr('data-title') || 'Media';
            var _multiple = '1' == $parent.attr('data-multiple') ? true : false;
            var _type = $parent.attr('data-type') || 'image';
            var _idtarget = $parent.attr('data-target');

            //Bind click event on button
            $self.bind('click', function (e) {
                e.preventDefault();

                //Set the wp.media post id so the uploader grabs the ID we want when initialised
                wp.media.model.settings.post.id = _wpid;

                //Create the media frame.
                var file_frame = wp.media.frames.file_frame = wp.media({
                    title: _title,
                    library: {
                        type: _type
                    },
                    /*frame: 'post', BUG: medialib popin does NOT send response if this option is enabled*/
                    multiple: _multiple
                });

                //Bind event when medialib popin is opened
                file_frame.on('open', function () {
                    //Check if there are results
                    if (!$result.length) {
                        return;
                    }

                    //Get selected items
                    var _selection = file_frame.state().get('selection');

                    //Get all selected medias on multiple choices
                    if (_multiple) {
                        $.each($result.find('img'), function (index,elem) {
                            attachment = wp.media.attachment(this.id);
                            attachment.fetch();
                            _selection.add(attachment ? [attachment] : []);
                        });
                    }
                });

                //Bind event when an media is selected
                file_frame.on('select', function (evt) {
                    var _selection = file_frame.state().get('selection');

                    //Check if jQuery result element exists
                    if (!$result.length) {
                        $result = $('<div class="upload_image_result"></div>');
                        $parent.before($result);
                    }

                    //Delete all HTML in result block
                    $result.html('');

                    //Check if multiple selection is allowed
                    if (_multiple) {
                        //Get all selections
                        var _attachments = _selection.toJSON();
                        var _hidden = '';
                        var $li = $img = del = null;
                        $result.append('<ul></ul>');

                        //Iterates on them
                        for (var i = 0, len = _attachments.length; i < len; i++) {
                            _hidden += _attachments[i].id + ';' + _attachments[i].url + '||';

                            //Built item
                            $li = $('<li></li>');
                            $img = $('<img src="' + _attachments[i].url + '" id="' + _attachments[i].id + '" atl="" />');
                            $del = $('<a href="#" class="delete" data-target="' + _idtarget + '">' + _delete + '</a>');

                            //Display item
                            $li.append($img);
                            $li.append($del);
                            $result.find('ul').append($li);
                        }

                        //Put result in hidden input
                        $target.val('' + _hidden);
                    }
                    else {
                        //Get the first selection
                        var _attachment = _selection.first().toJSON();

                        //Display it
                        var $fig = $('<figure></figure>');
                        var $del = $('<a href="#" class="delete" data-target="' + _idtarget + '">' + _delete + '</a>');
                        var $img = $('<img />').attr({
                            src: _attachment.url,
                            id: _attachment.id,
                            alt: ''
                        });
                        $fig.append($img);
                        $fig.append($del);
                        $result.append($fig);

                        //Put result in hidden input
                        $target.val('' + _attachment.url);
                    }

                    //Restore the main post ID and delete file_frame
                    wp.media.model.settings.post.id = _wpid;
                    delete file_frame;
                });

                //Open the modal
                file_frame.open();
            });
        });

        //Upload input: delete button
        $('.upload a.delete').live('click', function (e) {
            e.preventDefault();
            var $self = $(this);
            var $parent = $self.parent();
            var $hidden = $('#' + $self.attr('data-target'));

            //Check if there is multiple medias or not
            var _multiple = 'FIGURE' == $parent[0].nodeName ? false : true;

            //Check if there are multiple medias or not
            if (_multiple) {
                //Delete value
                var todelete = $parent.find('img').attr('id') + ';' + $parent.find('img').attr('src') + '||';
                var newval = $hidden.val().replace(todelete, '');
                newval = '' == newval ? 'NONE' : newval;
                $hidden.val('' + newval);
            }
            else {
                //Delete value
                $hidden.val('NONE');
            }

            //Deleting animation
            $parent.css('backgroundColor', '#'+_delcolor);
            $parent.animate({
                opacity: '0'
            }, 'low', function () {
                $parent.remove();
            });
        });

        //Upload input: delete all button
        $.each($('a.delall'), function (index,elem) {
            var $self = $(this);
            var $target = $('#' + $self.attr('data-target') + '_upload_content').find('.upload_image_result li');
            var $hidden = $('#' + $self.attr('data-target'));

            //Bind click event
            $self.bind('click', function (e) {
                e.preventDefault();

                //Delete all values
                $hidden.val('NONE');

                //Deleting animation
                $.each($target, function (ind,el) {
                    var $that = $(this);

                    //Animate and delete item
                    $that.css('backgroundColor', '#'+_delcolor);
                    $that.animate({
                        opacity: '0'
                    }, 'low', function () {
                        $that.remove();
                    });
                });
            });
        });

        //Upload Wordpress default button
        $.each($('a.insert-media:not(.thickbox)'), function (index,elem) {
            var $self = $(this);

            $self.bind('click', function (e) {
                if ($self.parent().hasClass('customized')) {
                    return false;
                }
            });
        });
    });
})(jQuery);