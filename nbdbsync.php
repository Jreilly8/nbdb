<?php
  
  /**
   * A MVC Controller for the Model Class Nbdb 
   * 
   */
  
  include '/usr/local/www/full/path/to/nbdb.php';
  $sync= new Nbmemberdb;
  $sync->sync_db();
?>
