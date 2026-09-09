<?php
/**
 * Redirector de cortesia.
 *
 * La aplicacion vive en public/. Este archivo solo existe para que quien
 * escriba la ruta del proyecto sin "/public" llegue al sitio correcto.
 * No carga nada del sistema ni expone informacion alguna.
 */
header('Location: public/', true, 302);
header('X-Robots-Tag: noindex, nofollow');
exit;
