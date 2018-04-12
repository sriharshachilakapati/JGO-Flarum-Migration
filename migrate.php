<?php
// MIT License
//
// Copyright (c) 2017 Sri Harsha Chilakapati
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

require_once 'vendor/autoload.php';
include_once 'settings.php';

use s9e\TextFormatter\Bundles\Forum as TextFormatter;

try
{
    // Create the connections to both the databases, the original for SMF and the second for Flarum
    $jgo = new PDO('mysql:host=localhost;dbname=' . smf_dbname, smf_user, smf_pass);
    $fla = new PDO('mysql:host=localhost;dbname=' . fla_dbname, fla_user, fla_pass);

    $jgo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fla->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Start the migration process
    if (confirm("categories")) migrateCategories($jgo, $fla);
    if (confirm("boards"))     migrateBoards($jgo, $fla);
    if (confirm("users"))      migrateUsers($jgo, $fla);
    if (confirm("posts"))      migratePosts($jgo, $fla);
}
catch (PDOException $e)
{
    echo $e->getMessage();
}

/**
 * Function to migrate the categories from the SMF forum to the Flarum database. It considers the categories as the first
 * level tags, meaning that the first level tags should be selected for all the discussions.
 */
function migrateCategories($jgo, $fla)
{
    // Query the existing categories from the SMF backend
    $stmt = $jgo->query('SELECT * FROM `jgoforums_categories`');
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    // Delete all existing tags from the Flarum forum and reset the AUTO_INCREMENT count for the table
    $fla->exec('DELETE FROM `jgoforumstags`');
    $fla->exec('ALTER TABLE `jgoforumstags` AUTO_INCREMENT = 1');

    // Insert statement to insert the tags into the table
    $insert = $fla->prepare('INSERT INTO `jgoforumstags` (name, slug, description, color, position) VALUES (?, ?, ?, ?, ?)');

    // Counters for calculating the percentages
    $total = $jgo->query('SELECT COUNT(*) FROM `jgoforums_categories`')->fetchColumn();
    $done = 0;

    // For each category in the SMF backend
    while ($row = $stmt->fetch())
    {
        // Display percentage
        $done++;
        echo "Migrating categories: " . $done . "/" . $total . " (" . ((int) ($done / $total * 100)) . "%)\r";

        // Transform the data to new record format
        $data = array(
            preg_replace('(\\&amp;)', '&', $row->name),
            slugify($row->name),
            '',
            ($row->catOrder % 2 === 0) ? '#2980b9' : '#34495e',
            $row->catOrder
        );

        // Insert the new tag into Flarum
        $insert->execute($data);
    }

    echo "\n";
}

/**
 * Function to migrate boards from the SMF backend to the Flarum backend. Since Flarum doesn't support boards and categories,
 * this function translates them to tags. Each board is generated as a second order tag (child of first order tags, which
 * correspond to the categories) and the child boards are generated as secondary tags.
 */
function migrateBoards($jgo, $fla)
{
    // The query to find all the boards in the SMF forum
    $sql = <<<SQL
        SELECT jgoforums_boards.name AS bname, jgoforums_categories.name AS cname, jgoforums_boards.description,
        jgoforums_boards.childLevel, jgoforums_boards.boardOrder FROM jgoforums_boards
        LEFT JOIN jgoforums_categories ON jgoforums_boards.ID_CAT=jgoforums_categories.ID_CAT;
SQL;

    $stmt = $jgo->query($sql);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    // The query to insert the new transformed tags
    $insert = $fla->prepare('INSERT INTO `jgoforumstags` (name, slug, description, color, position, parent_id) VALUES (?, ?, ?, ?, ?, ?)');

    // Counters to display the progress info
    $total = $jgo->query('SELECT COUNT(*) FROM `jgoforums_boards`')->fetchColumn();
    $done = 0;

    // For each board in the SMF backend
    while ($row = $stmt->fetch())
    {
        // Display the updated percentage
        $done++;
        echo "Migrating boards: " . $done . "/" . $total . " (" . ((int) ($done / $total * 100)) . "%)\r";

        // childLevel is 0 for first hand boards
        if ($row->childLevel === "0")
        {
            // Get the category it is associated to, to set the parent relationship
            $stmt2 = $fla->query('SELECT * FROM `jgoforumstags` WHERE slug=\'' . slugify($row->cname) . '\'');
            $stmt2->setFetchMode(PDO::FETCH_OBJ);

            $row2 = $stmt2->fetch();

            // Compute the new record to be inserted
            $data = array(
                preg_replace('(\\&amp;)', '&', $row->bname),
                slugify($row->bname),
                $row->description,
                '#bdc3c7',
                $row->boardOrder,
                $row2->id
            );

            // Insert the new record into the database
            $insert->execute($data);
        }
        // All the child boards are secondary tags
        else
        {
            // Compute the new record as secondary tag
            $data = array(
                preg_replace('(\\&amp;)', '&', $row->bname),
                slugify($row->bname),
                $row->description,
                '#bdc3c7',
                NULL,
                NULL
            );

            // Insert the tag computed into the database
            $insert->execute($data);
        }
    }

    echo "\n";
}

/**
 * Function to migrate the users from the SMF forum to the new Flarum backend. This function is also responsible to translate
 * the member groups from the old forum to the new forum and associate them in the new forum. However the post stats and
 * the profile picture is not migrated. The users will also be migrated without a password, and hence they are required to
 * click on the forgot password link and generate a new password.
 */
function migrateUsers($jgo, $fla)
{
    // Clear existing users from the forum except for the admin account created while installing Flarum.
    // Also reset the AUTO_INCREMENT values for the tables.
    $fla->exec('DELETE FROM `jgoforumsusers` WHERE id != 1');
    $fla->exec('ALTER TABLE `jgoforumsusers` AUTO_INCREMENT = 2');
    $fla->exec('DELETE FROM `jgoforumsusers_groups` WHERE user_id != 1');
    $fla->exec('ALTER TABLE `jgoforumsusers_groups` AUTO_INCREMENT = 2');

    // Create a helper table for all the users, which stores the SMF user ids with the Flarum equivalents
    $sql = <<<SQL
        CREATE TABLE IF NOT EXISTS `migrated_users` (
            jgo_id VARCHAR(100),
            fla_id VARCHAR(100)
        );
SQL;
    $jgo->exec($sql);
    $jgo->exec('TRUNCATE TABLE `migrated_users`');

    // Statement to insert the migrated user id into the helper table
    $sql = "INSERT INTO `migrated_users` (jgo_id, fla_id) VALUES (?, ?);";
    $insert_helper = $jgo->prepare($sql);

    // The query to select the existing users from the SMF backend
    $sql = <<<SQL
        SELECT ID_MEMBER, memberName, emailAddress, dateRegistered, lastLogin, personalText, is_activated, ID_GROUP
        FROM `jgoforums_members` ORDER BY ID_MEMBER ASC
SQL;

    $stmt = $jgo->query($sql);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    // Counters for displaying the progress info
    $total = $jgo->query('SELECT COUNT(*) FROM `jgoforums_members`')->fetchColumn();
    $done = 0;

    // The query to insert the new users into the Flarum database
    $sql = <<<SQL
        INSERT INTO `jgoforumsusers` (id, username, email, is_activated, password, bio, join_time, last_seen_time)
        VALUES (
            ?, ?, ?, ?, '', ?, ?, ?
        );
SQL;

    $insert = $fla->prepare($sql);
    $insert2 = $fla->prepare('INSERT INTO `jgoforumsusers_groups` (user_id, group_id) VALUES (?, ?)');

    // The groupMap. It is a mapping from the SMF member groups to the Flarum groups. The following are present in the
    // original SMF backend.
    //
    // +----------+-------------------------------+
    // | ID_GROUP | groupName                     |
    // +----------+-------------------------------+
    // |        1 | Administrator                 |
    // |        2 | Global Moderator              |
    // |        3 | Moderator                     |
    // |        4 | JGO n00b                      |
    // |        5 | Jr. Member                    |
    // |        6 | Full Member                   |
    // |        7 | Sr. Member                    |
    // |        8 | JGO Ninja                     |
    // |        9 | JGO Strike Force              |
    // |       10 | JGO Neuromancer               |
    // |       11 | JGO Wizard                    |
    // |       12 | JGO Kernel                    |
    // |       13 | Showcase Moderator            |
    // |       14 | Section Moderator             |
    // |       15 | Overlord                      |
    // |       16 | &#171; League of Dukes &#187; |
    // +----------+-------------------------------+
    //
    // But the Flarum has only four groups. For this sake, we are converting each and every user into the following four
    // groups which are supported by Flarum. Though new groups can be added to the Flarum, it requires customized extensions
    // to take care of all the groups and recalculate them after each user does a post.
    //
    // +----+---------------+-------------+
    // | id | name_singular | name_plural |
    // +----+---------------+-------------+
    // |  1 | Admin         | Admins      |
    // |  2 | Guest         | Guests      |
    // |  3 | Member        | Members     |
    // |  4 | Mod           | Mods        |
    // +----+---------------+-------------+
    //
    // This map exists to ease the conversion from one table to another. Additionally some members in the SMF backend
    // had the ID_GROUP attribute set to 0, which is not documented in the SMF sources. Hence this script considers everyone
    // else as the regular members.
    $groupMap = array(
        0 => 3,
        1 => 1,
        3 => 4,
        2 => 3,
        4 => 3,
        5 => 3,
        6 => 3,
        7 => 3,
        8 => 3,
        9 => 3,
        10 => 3,
        11 => 3,
        12 => 3,
        13 => 4,
        14 => 4,
        15 => 1,
        16 => 1
    );

    $userId = 2;

    // For each user in the SMF database
    while ($row = $stmt->fetch())
    {
        // Update and display the progess info
        $done++;
        echo "Migrating users: " . $done . "/" . $total . " (" . ((int) ($done / $total * 100)) . "%)\r";

        // Transform the user to the Flarum table
        $data = array(
            $userId,
            $row->memberName,
            $row->emailAddress === "" ? "email" . $row->ID_MEMBER . "@example.com" : $row->emailAddress,
            $row->is_activated !== "0" ? 1 : 0,
            $row->personalText,
            date(DateTime::ATOM, $row->dateRegistered),
            date(DateTime::ATOM, $row->lastLogin)
        );

        try
        {
            // Insert the user into the database
            $insert->execute($data);

            // Compute the group record for this user
            $data = array(
                $userId,
                $groupMap[(int) $row->ID_GROUP]
            );

            try
            {
                // Insert the group record into the database
                $insert2->execute($data);

                // Insert the helper record into the helper table
                $insert_helper->execute(array($row->ID_MEMBER, $userId));
            }
            catch (Exception $e)
            {
                echo "Error while updating member group for the following user:\n";
                var_dump($data);
                echo "The message was: " . $e->getMessage() . "\n";
            }
        }
        catch (Exception $e)
        {
            echo "Error while porting the following user:\n";
            var_dump($row);
            echo "The message was: " . $e->getMessage() . "\n";
        }
        finally
        {
            $userId++;
        }
    }

    echo "\n";
}

/**
 * Function to migrate the posts from the SMF backend to the new Flarum backend. Uses the default bundle to transform
 * the posts data from BBCode into the data format of Flarum.
 */
function migratePosts($jgo, $fla)
{
    // Clear existing posts from the forum. Also reset the AUTO_INCREMENT values for the tables.
    $fla->exec('DELETE FROM `jgoforumsposts`');
    $fla->exec('ALTER TABLE `jgoforumsposts` AUTO_INCREMENT = 1');
    $fla->exec('DELETE FROM `jgoforumsdiscussions`');
    $fla->exec('ALTER TABLE `jgoforumsdiscussions` AUTO_INCREMENT = 1');
    $fla->exec('DELETE FROM `jgoforumsdiscussions_tags`');
    $fla->exec('ALTER TABLE `jgoforumsdiscussions_tags` AUTO_INCREMENT = 1');

    // SQL query to fetch the topics from the JGO database
    $sql = <<<SQL
        SELECT
            t.ID_TOPIC, t.ID_MEMBER_STARTED, t.ID_BOARD, m.subject, m.posterTime, m.posterName, t.numReplies,
            u.fla_id
        FROM
            `jgoforums_topics` t
        LEFT JOIN
            `jgoforums_messages` m ON t.ID_FIRST_MSG = m.ID_MSG
        LEFT JOIN
            `migrated_users` u ON t.ID_MEMBER_STARTED = u.jgo_id
SQL;

    $topics = $jgo->query($sql);
    $topics->setFetchMode(PDO::FETCH_OBJ);

    // SQL statement to insert the topic into the Flarum backend
    $sql = <<<SQL
        INSERT INTO `jgoforumsdiscussions` (
            id, title, comments_count, participants_count, number_index, start_time,
            start_user_id, start_post_id, last_time, last_user_id, last_post_id, last_post_number, slug
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        );
SQL;
    $insert_topic = $fla->prepare($sql);

    // SQL statement to insert the post into the Flarum backend
    $sql = <<<SQL
        INSERT INTO `jgoforumsposts` (
            `id`, `discussion_id`, `type`, `number`, `time`, `user_id`, `content`
        ) VALUES (
            ?, ?, 'comment', ?, ?, ?, ?
        );
SQL;
    $insert_post = $fla->prepare($sql);

    $topic_count = 1;
    $message_id = 1;

    while ($topic = $topics->fetch())
    {
        $sql = <<<SQL
            SELECT m.ID_MSG, m.body, m.posterTime, u.fla_id
            FROM `jgoforums_messages` m
            LEFT JOIN `migrated_users` u ON m.ID_MEMBER = u.jgo_id
            WHERE m.ID_TOPIC = {$topic->ID_TOPIC}
SQL;

        $posts = $jgo->query($sql);
        $posts->setFetchMode(PDO::FETCH_OBJ);

        $start_post_id = $message_id;
        $last_post_id = $message_id;
        $post_counter = 0;

        $participants = array();

        while ($post = $posts->fetch())
        {
            $data = array(
                $last_post_id = $message_id++,
                $topic_count,
                $post_counter++,
                date(DateTime::ATOM, $post->posterTime),
                $post->fla_id,
                TextFormatter::parse(replaceBodyStrings($post->body))
            );

            $participants[] = $post->fla_id;

            $insert_post->execute($data);
        }

        $data = array(
            $topic_count,
            preg_replace("/&quot;/", '"', $topic->subject),
            $topic->numReplies,
            count(array_unique($participants)),
            $topic->numReplies,
            date(DateTime::ATOM, $topic->posterTime),
            $topic->fla_id,
            $last_post_id,
            date(DateTime::ATOM, $topic->posterTime),
            $topic->fla_id,
            $last_post_id,
            $post_counter,
            slugify($topic->subject)
        );

        $insert_topic->execute($data);

        if ($topic_count++ >= 10)
        return;
    }
}

/**
 * Utility function to replace some characters prior to storing in Flarum.
 */
function replaceBodyStrings($str)
{
    $str = preg_replace("/\<br\>/", "\n", $str);
    $str = preg_replace("/&nbsp;/", " ", $str);
    $str = preg_replace("/&quot;/", "\"", $str);
    $str = preg_replace("/&lt;/", "<", $str);
    $str = preg_replace("/&gt;/", ">", $str);
    return preg_replace("/\[quote\][\s\t\r\n]*\[\/quote\]/", "", $str);
}

/**
 * Utility function to compute slugs for topics, categories and boards.
 */
function slugify($text)
{
    $text = preg_replace('(\\&.+;)', "", $text);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);

    if (empty($text))
        return 'n-a';

    return $text;
}

/**
 * Asks a user a confirmation message and returns true or false based on yes/no.
 */
function confirm($text)
{
    echo "Migrate $text? ";
    $str = trim(strtolower(fgets(STDIN)));

    switch ($str)
    {
        case "yes":
        case "y":
        case "true":
        case "t":
        case "1":
        case "ok":
        case "k":
            return true;
    }

    return false;
}
