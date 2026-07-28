<?php
require __DIR__ . '/lib/triops.php';

http_response_code(404);
t_page_open('Not found', false);
?>
<div style="max-width:32rem;margin:3rem auto;text-align:center">
  <img src="./assets/triops-logo.png" alt="triops" style="height:56px;margin-bottom:1rem">
  <h2>404</h2>
  <p>No page there. <a href="./index.php">Back to the endpoint list</a>.</p>
</div>
<?php
t_page_close(false);
t_page_end();
