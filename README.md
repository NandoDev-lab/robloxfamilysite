# Roblox Family Site

Site estático para divulgar o canal e permitir interação por meio do quiz e do
bate-papo.

## Publicar no InfinityFree

1. Crie um banco MySQL no painel do InfinityFree e importe
   [`backend/chat-schema.sql`](./backend/chat-schema.sql) pelo phpMyAdmin.
   O script cria tabelas de contas, sessões, moderação e mensagens; pode ser
   importado novamente sem recriar tabelas existentes.
2. Copie `backend/config.example.php` para `backend/config.php` e preencha os
   dados do MySQL exibidos no painel. O arquivo real de configuração é ignorado
   pelo Git e protegido por `.htaccess`.
3. Gere um hash de senha com PHP no seu computador:

   ```powershell
   php -r "echo password_hash('SUA-SENHA-FORTE', PASSWORD_DEFAULT), PHP_EOL;"
   ```

4. No phpMyAdmin, crie a primeira conta administrativa usando o hash gerado:

   ```sql
   INSERT INTO users (username, email, password_hash, role, chat_color)
   VALUES ('Administrador', 'SEU-EMAIL', 'HASH_GERADO', 'admin', '#ff8c00');
   ```

   Use uma senha forte e substitua `HASH_GERADO` pelo resultado do comando;
   nunca coloque uma senha em texto puro no banco ou no código. Contas comuns
   exigem senha entre 10 e 72 bytes.
5. Envie os arquivos do site para `htdocs`, mantendo as pastas `backend`,
   `frontend` e `admin`.

O PHP deve ter PDO MySQL habilitado e suporte a sessões. O chat consulta novas
mensagens a cada três segundos, sem Node.js ou WebSockets. As mensagens só são
consultáveis por dez minutos e a limpeza física do banco roda no máximo uma vez
por minuto enquanto o chat ou painel estiver em uso. O painel fica em
`/admin/index.html`: mostra membros online (atividade recente), permite
silenciar/remover silêncio, bloquear/desbloquear contas e encerrar sessões
individuais. Silenciar impede o envio; bloquear impede o login e encerra todas
as sessões ativas. As contas comuns são criadas publicamente no chat; o papel
administrativo só pode ser atribuído no banco.

## Executar localmente

Configure o esquema, o `backend/config.php` e a conta administrativa como
descrito acima. Sirva a raiz do projeto com um servidor PHP, por exemplo
`php -S localhost:8000`. Abra `http://localhost:8000`; não abra as páginas
diretamente como arquivos locais.
