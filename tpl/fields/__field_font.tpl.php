                    <!-- Content font <?php echo $id ?> -->
                    <div id="<?php echo $id ?>_content" class="<?php echo $group ? 'smallbox' : 'stuffbox' ?>">
                        <h3>
                            <label><?php echo $title ?></label>
                        </h3>

                        <div class="inside">
                            <fieldset>
                                <?php foreach ($options as $key => $option): ?>
                                    <?php
                                        $selected = $key == $val ? true : false;
                                        $for = $id . '_' . $key;
                                    ?>
                                    <label for="<?php echo $for ?>" class="selectit image image-radio <?php echo $selected ? 'selected' : '' ?>">
                                        <img src="<?php echo $option ?>" alt="" width="140" height="25" />
                                        <input type="radio" name="<?php echo $id ?>" id="<?php echo $for ?>" value="<?php echo $key ?>" <?php echo $selected ? 'checked="checked" ' : '' ?> />
                                    </label>
                                <?php endforeach ?>
                            </fieldset>

                            <p><?php echo $description ?></p>
                        </div>
                    </div>
                    <!-- /Content font <?php echo $id ?> -->