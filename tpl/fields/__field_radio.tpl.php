                    <!-- Content radio <?php echo $id ?> -->
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
                                    <p>
                                        <label for="<?php echo $for ?>" class="selectit radio <?php echo $selected ? 'selected' : '' ?>">
                                            <input type="radio" name="<?php echo $id ?>" id="<?php echo $for ?>" value="<?php echo $key ?>" <?php echo $selected ? 'checked="checked" ' : '' ?> />
                                            <?php echo $option ?>
                                        </label>
                                    </p>
                                <?php endforeach ?>
                            </fieldset>

                            <p><?php echo $description ?></p>
                        </div>
                    </div>
                    <!-- /Content radio <?php echo $id ?> -->