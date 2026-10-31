<?php

class Store
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
        if (!is_file($file)) {
            $this->write($this->seed());
        }
    }

    public function all(): array
    {
        $fp = fopen($this->file, 'c+');
        if (!$fp) {
            throw new RuntimeException('Não foi possível abrir o banco.');
        }
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($raw ?: '{}', true);
        return is_array($data) ? $data : $this->seed();
    }

    public function update(callable $mutator): array
    {
        $fp = fopen($this->file, 'c+');
        if (!$fp) {
            throw new RuntimeException('Não foi possível abrir o banco.');
        }
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data) || empty($data)) {
            $data = $this->seed();
        }
        $data = $mutator($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $data;
    }

    private function write(array $data): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function seed(): array
    {
        $guestNames = [
            'Leide Ferreira', 'Francisco de Assis', 'Ana Paula Ferreira', 'Antonio Victor',
            'Amanda Mayra', 'Murilo Sales', 'Dayane Queiroz', 'Valmon Pereira', 'Maria Queiroz',
            'José Antônio De Lima', 'Roberta Gomes', 'Paulo César', 'Leila Ferreira', 'Henaira Paula',
            'Samuel Machado', 'João Paulo Ferreira', 'Nilton César', 'Maria Eduarda', 'Bruno César',
            'Mariza Alencar', 'João Emanuel', 'Vitor César', 'Julia Nair', 'Maria Lucia Ferreira',
            'Ednaldo Oliveira', 'Gabriella Ferreira', 'Eliete Queiroz', 'Isaque Amancio',
            'Antônio Queiroz', 'Isabel Queiroz', 'Carmem Queiroz', 'Josias Moura', 'Iolanda Queiroz',
            'Lourival Gonçalves', 'Gabriel Queiroz', 'Jussara Valerie', 'Eunice Queiroz',
            'Ian Phidias Queiroz', 'José Antônio de Freitas', 'Claudia Queiroz', 'Timóteo Queiroz',
            'Ana Luísa Oliveira', 'Severino Júnior', 'Maria Abadia Lima', 'Solange Lima',
            'Meirevanda Fontes', 'Ângelo Galdino', 'Meireangela Fontes', 'Carlos Eduardo Fontes',
            'Pedro Caetano', 'Maria do Carmo', 'Thaynara Lima', 'Maria Tomázia', 'Carlos Eduardo',
            'Larissa Lima', 'Célio Januário', 'Vera Santana', 'Cleiton Januário', 'Helena Lima',
            'Giovane Lima', 'Davi Lima', 'Maria Toinha', 'Nildomar Nogueira', 'Eduarda Santana',
            'Henrique Santana', 'Jonathan Araujo', 'Divanelia Araujo', 'Eric Araujo', 'Namorada Eric',
            'Carlos Araujo', 'Sara Araujo', 'Elise Amanda', 'Murilo Santana', 'Socorro Januário',
            'Celida Costa', 'Danielle Gonçalves', 'Júnior Raio', 'Karine Roque', 'Matheus Lima',
            'Giovana Guimarães', 'Erick Veríssimo', 'Virginia Lima', 'Felipe Gomes', 'Deborah',
            'Laudiane Inácio', 'Carlos Augusto Dias', 'Silvania Amancio', 'Itamar Amancio',
            'Juliana Amancio', 'Carlos Augusto', 'Isabela Amancio', 'Jair Almeida', 'José veras',
            'Marleide Cavalcanti', 'Ian Veras', 'Ana Carolina Almeida', 'Helena Werner',
            'Marco Antonio', 'Sávia Oliveira', 'Jacione Caetano', 'Ângela Soares', 'Thamiris Galeno',
            'João Felício', 'Renata Felício', 'Gabriel Oliveira', 'Talia Oliveira', 'Gabriel Assis',
            'Letícia Costa', 'Henrique Oliveira', 'Fran Oliveira', 'Patrick Raniere', 'Heversson Jacob',
            'Priscila Jacob', 'Wesley Amorim', 'Kezia Amorim', 'Mara Santana', 'Eulina Dantas',
            'Redjanio Dantas', 'Letícia Dantas', 'Michelle Xavier', 'Ualter Xavier', 'Luther Xavier',
            'Mikaele Xavier', 'Fabíola Diane', 'Edelson', 'Marciano Andrade', 'Suellen',
            'Tanisthia Urcino', 'Athos Henrique', 'Thayla', 'Matheus Garces', 'Lucas Garces',
            'Gabriel Dias', 'Rebeca Dias', 'Thallys Thiago', 'Samuel Amaral', 'Carol Reis',
            'Veronica Celestino', 'Manoel Celestino', 'Basílio Aguiar', 'Esposa', 'Vitor Lopes',
            'Ketley Lopes', 'Waguinho Moraes', 'José Marcelo', 'Lemuel Silva', 'Patrícia Cecília',
            'Beju', 'Danyerika', 'Amanda Roanes', 'Filipe Carvalho', 'Maria Letícia Dantas',
            'Luís Felipe', 'Amanda Gonçalves', 'Gabriel Portela', 'Isaque Martins',
            'Vitória Castelo Branco', 'Rebecca Beatriz', 'Ana paula Costa', 'Igor França',
            'Asaph Matheus', 'Ester Loyola', 'Pedro paulo', 'Evelyn Kaylane', 'Tabitha Tammys',
            'Thomas Alves', 'Poliana Andréia', 'Wecius Wallace', 'Maria Eduarda Guimarães',
            'Mikael Alves', 'Queren Hapuque', 'Jesse Wilker', 'Pedro Lucas', 'Acsa Alves',
            'Elienay da Silva',
        ];

        $guests = [];
        $id = 1;
        foreach ($guestNames as $name) {
            $guests[] = [
                'id' => $id++,
                'name' => $name,
                'created_at' => date('c'),
            ];
        }

        $giftSeed = [
            ['name' => 'Painel de TV', 'price' => 800, 'image' => 'img/painel de tv.png'],
            ['name' => 'Sofá', 'price' => 2500, 'image' => 'img/sofá.png'],
            ['name' => 'Rack', 'price' => 600, 'image' => 'img/rack.png'],
            ['name' => 'TV Samsung', 'price' => 2200, 'image' => 'img/Tv samsung.png'],
            ['name' => 'Jogo de toalhas', 'price' => 180, 'image' => 'img/Jogo de toalhas.png'],
            ['name' => 'Forno inox (Oster)', 'price' => 450, 'image' => 'img/Forno inox (Oster).png'],
            ['name' => 'Mesa de jantar c/ cadeiras', 'price' => 1800, 'image' => 'img/Mesa de jantar c cadeiras.png'],
        ];

        $gifts = [];
        $gid = 1;
        foreach ($giftSeed as $g) {
            $gifts[] = [
                'id' => $gid++,
                'name' => $g['name'],
                'price' => (float) $g['price'],
                'image' => $g['image'],
                'details' => '',
                'delivery_mode' => 'pix', // mao | pix
                'max_people' => 1,
                'claimed_count' => 0,
                'is_active' => true,
                'created_at' => date('c'),
            ];
        }

        return [
            'meta' => ['next_guest_id' => $id, 'next_gift_id' => $gid, 'next_confirmation_id' => 1, 'next_claim_id' => 1],
            'guests' => $guests,
            'confirmations' => [],
            'gifts' => $gifts,
            'gift_claims' => [],
            'tokens' => [],
        ];
    }
}
