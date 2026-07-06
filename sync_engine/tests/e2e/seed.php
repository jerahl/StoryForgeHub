<?php
// Seed the e2e sqlite DB: two books, alice is a member of 'echo' only.
// Prints alice's personal API token on stdout.
require_once getenv('REPO') . '/src/repo.php';
migrate();
save_book(['id'=>'echo', 'folder'=>'echo', 'title'=>'Echo', 'sort_order'=>1]);
save_book(['id'=>'alien', 'folder'=>'alien', 'title'=>'Alien', 'sort_order'=>2]);
save_entry('echo', 'characters', ['slug'=>'aria', 'name'=>'Aria', 'status'=>'canon', 'type'=>'Character',
    'fields'=>[['label'=>'Species','value'=>'Human']],
    'sections'=>[['h'=>'Overview','body'=>'Aria commands the northern watchtower.']]]);
q("INSERT INTO chapters (book_id,num,title,file,status,words,body) VALUES
   ('echo','01','The Wall','ch01.md','drafted','9','# Chapter 1'||char(10)||char(10)||'Snow fell on the watchtower.')");
q("INSERT INTO chapters (book_id,num,title,file,status,words,body) VALUES
   ('alien','01','Contact','a01.md','drafted','4','First contact.')");
save_note_page('echo', 'outline', 'Outline', "Act one ends at the watchtower siege.\n");
$alice = create_user('alice@example.com', 'Alice', 'password1', 0);
add_book_member('echo', $alice, 'editor');
echo create_api_token($alice, 'e2e'), "\n";
