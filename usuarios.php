<?php
/*
 * Usuarios de demostración.
 * Genera el hash con password_hash() y verifica con password_verify().
 * En producción, no guardes credenciales reales en un archivo versionado
 * dentro de GitHub. Usa variables de entorno o una base de datos.
 */

return [
    'admin' => [
        // Contraseña de demostración: Admin123!
        'password_hash' => '$2y$12$lYuSWKMRTU3CPSuuxS2keu6f0n9il/BDOePkYdUMhflWAiqTI0sJ.',
        'nombre' => 'Administrador',
    ],
];
