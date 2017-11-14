<?php

include_once('settings.php');

try
{
    $jgo = new PDO('mysql:host=localhost;dbname=' . smf_dbname, smf_user, smf_pass);
    $fla = new PDO('mysql:host=localhost;dbname=' . fla_dbname, fla_user, fla_pass);

    $jgo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fla->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    migrateCategories($jgo, $fla);
    migrateBoards($jgo, $fla);
}
catch (PDOException $e)
{
    echo $e->getMessage();
}

function migrateCategories($jgo, $fla)
{
    $stmt = $jgo->query('SELECT * FROM `jgoforums_categories`');
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    $fla->exec('DELETE FROM `jgoforumstags`');
    $fla->exec('ALTER TABLE `jgoforumstags` AUTO_INCREMENT = 1');

    $insert = $fla->prepare('INSERT INTO `jgoforumstags` (name, slug, description, color, position) VALUES (?, ?, ?, ?, ?)');

    while ($row = $stmt->fetch())
    {
        $data = array(
            preg_replace('(\\&amp;)', '&', $row->name),
            slugify($row->name),
            '',
            ($row->catOrder % 2 === 0) ? '#2980b9' : '#34495e',
            $row->catOrder
        );

        $insert->execute($data);
    }
}

function migrateBoards($jgo, $fla)
{
    $sql = <<<SQL
        SELECT jgoforums_boards.name AS bname, jgoforums_categories.name AS cname, jgoforums_boards.description,
        jgoforums_boards.childLevel, jgoforums_boards.boardOrder FROM jgoforums_boards
        LEFT JOIN jgoforums_categories ON jgoforums_boards.ID_CAT=jgoforums_categories.ID_CAT;
SQL;

    $stmt = $jgo->query($sql);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    $insert = $fla->prepare('INSERT INTO `jgoforumstags` (name, slug, description, color, position, parent_id) VALUES (?, ?, ?, ?, ?, ?)');

    while ($row = $stmt->fetch())
    {
        if ($row->childLevel === "0")
        {
            $stmt2 = $fla->query('SELECT * FROM `jgoforumstags` WHERE slug=\'' . slugify($row->cname) . '\'');
            $stmt2->setFetchMode(PDO::FETCH_OBJ);

            $row2 = $stmt2->fetch();

            $data = array(
                preg_replace('(\\&amp;)', '&', $row->bname),
                slugify($row->bname),
                $row->description,
                '#bdc3c7',
                $row->boardOrder,
                $row2->id
            );

            $insert->execute($data);
        }
        else
        {
            $data = array(
                preg_replace('(\\&amp;)', '&', $row->bname),
                slugify($row->bname),
                $row->description,
                '#bdc3c7',
                NULL,
                NULL
            );

            $insert->execute($data);
        }
    }
}

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