<?php
/**
 * Parsedown
 * Version 1.7.4 (Compatible PHP 5.3+)
 * 
 * ATTENTION : Ce fichier contient une version simplifiée de Parsedown
 * pour la démonstration. Pour une utilisation en production, téléchargez
 * la version complète depuis : https://github.com/erusev/parsedown
 * 
 * Téléchargement : 
 * wget https://raw.githubusercontent.com/erusev/parsedown/master/Parsedown.php
 * 
 * Pour cette démonstration, voici une implémentation minimale fonctionnelle :
 */

class Parsedown
{
    const version = '1.7.4';
    
    function text($text)
    {
        # make sure no definitions are set
        $this->DefinitionData = array();

        # standardize line breaks
        $text = str_replace(array("\r\n", "\r"), "\n", $text);

        # remove surrounding line breaks
        $text = trim($text, "\n");

        # split text into lines
        $lines = explode("\n", $text);

        # iterate through lines to identify blocks
        $markup = $this->lines($lines);

        # trim line breaks
        $markup = trim($markup, "\n");

        return $markup;
    }

    protected function lines(array $lines)
    {
        $CurrentBlock = null;

        foreach ($lines as $line)
        {
            if (chop($line) === '')
            {
                if (isset($CurrentBlock))
                {
                    $CurrentBlock['interrupted'] = true;
                }

                continue;
            }

            if (strpos($line, "\t") !== false)
            {
                $parts = explode("\t", $line);

                $line = $parts[0];

                unset($parts[0]);

                foreach ($parts as $part)
                {
                    $shortage = 4 - mb_strlen($line, 'utf-8') % 4;

                    $line .= str_repeat(' ', $shortage);
                    $line .= $part;
                }
            }

            $indent = 0;

            while (isset($line[$indent]) and $line[$indent] === ' ')
            {
                $indent ++;
            }

            $text = $indent > 0 ? substr($line, $indent) : $line;

            # Heading
            if ($text[0] === '#')
            {
                $level = 1;
                while (isset($text[$level]) and $text[$level] === '#')
                {
                    $level ++;
                }

                if ($level > 6)
                {
                    $level = 6;
                }

                $text = trim($text, '# ');

                if (isset($CurrentBlock))
                {
                    $Blocks []= $CurrentBlock;
                    unset($CurrentBlock);
                }

                $Blocks []= array(
                    'element' => array(
                        'name' => 'h' . min(6, $level),
                        'text' => $this->parseInline($text),
                        'handler' => 'line',
                    ),
                );

                continue;
            }

            # List
            if (preg_match('/^([-*+]|\d+\.)\s/', $text, $matches))
            {
                $marker = $matches[1];
                
                $listType = $marker === '-' || $marker === '*' || $marker === '+' ? 'ul' : 'ol';

                $markerWithoutWhitespace = strstr($marker, '.', true) ?: $marker;

                $text = trim(substr($text, strlen($matches[0])));

                if (isset($CurrentBlock) and $CurrentBlock['type'] !== 'List')
                {
                    $Blocks []= $CurrentBlock;
                    unset($CurrentBlock);
                }

                if ( ! isset($CurrentBlock) or $CurrentBlock['list'] !== $listType)
                {
                    $CurrentBlock = array(
                        'type' => 'List',
                        'list' => $listType,
                        'element' => array(
                            'name' => $listType,
                            'text' => '',
                        ),
                        'items' => array(),
                    );
                }

                $CurrentBlock['items'] []= array(
                    'text' => $text,
                );

                continue;
            }

            # Quote
            if ($text[0] === '>')
            {
                $text = trim(ltrim($text, '>'));

                if (isset($CurrentBlock))
                {
                    $Blocks []= $CurrentBlock;
                    unset($CurrentBlock);
                }

                $Blocks []= array(
                    'element' => array(
                        'name' => 'blockquote',
                        'handler' => 'lines',
                        'text' => array($text),
                    ),
                );

                continue;
            }

            # Code
            if (preg_match('/^```(\w*)/', $text, $matches))
            {
                $language = isset($matches[1]) ? $matches[1] : '';
                
                if (isset($CurrentBlock))
                {
                    $Blocks []= $CurrentBlock;
                    unset($CurrentBlock);
                }

                $CurrentBlock = array(
                    'type' => 'Code',
                    'element' => array(
                        'name' => 'pre',
                        'handler' => 'element',
                        'text' => array(
                            'name' => 'code',
                            'text' => '',
                        ),
                    ),
                );

                continue;
            }

            # Default: Paragraph
            if (isset($CurrentBlock) and $CurrentBlock['type'] === 'Paragraph')
            {
                if (isset($CurrentBlock['interrupted']))
                {
                    $Blocks []= $CurrentBlock;

                    $CurrentBlock = array(
                        'type' => 'Paragraph',
                        'element' => array(
                            'name' => 'p',
                            'text' => $text,
                            'handler' => 'line',
                        ),
                    );

                    unset($CurrentBlock['interrupted']);
                }
                else
                {
                    $CurrentBlock['element']['text'] .= "\n" . $text;
                }
            }
            else
            {
                if (isset($CurrentBlock))
                {
                    $Blocks []= $CurrentBlock;
                }

                $CurrentBlock = array(
                    'type' => 'Paragraph',
                    'element' => array(
                        'name' => 'p',
                        'text' => $text,
                        'handler' => 'line',
                    ),
                );
            }
        }

        # Add current block
        if (isset($CurrentBlock))
        {
            $Blocks []= $CurrentBlock;
        }

        # Convert blocks to HTML
        $markup = '';

        if (isset($Blocks))
        {
            foreach ($Blocks as $Block)
            {
                $markup .= $this->renderBlock($Block);
            }
        }

        return $markup;
    }

    protected function renderBlock($Block)
    {
        if ($Block['type'] === 'List')
        {
            $html = '<' . $Block['element']['name'] . '>';
            
            foreach ($Block['items'] as $item)
            {
                $html .= '<li>' . $this->parseInline($item['text']) . '</li>';
            }
            
            $html .= '</' . $Block['element']['name'] . '>';
            
            return $html;
        }

        $element = $Block['element'];
        
        if (isset($element['handler']))
        {
            if ($element['handler'] === 'line')
            {
                $text = $this->parseInline($element['text']);
            }
            elseif ($element['handler'] === 'lines')
            {
                $text = '';
                foreach ($element['text'] as $line)
                {
                    $text .= '<p>' . $this->parseInline($line) . '</p>';
                }
            }
            elseif ($element['handler'] === 'element')
            {
                $text = '<' . $element['text']['name'] . '>' . 
                        htmlspecialchars($element['text']['text']) . 
                        '</' . $element['text']['name'] . '>';
            }
            else
            {
                $text = $element['text'];
            }
        }
        else
        {
            $text = $element['text'];
        }

        return '<' . $element['name'] . '>' . $text . '</' . $element['name'] . '>';
    }

    protected function parseInline($text)
    {
        # Bold
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
        
        # Italic
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/_(.+?)_/', '<em>$1</em>', $text);
        
        # Code
        $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
        
        # Links
        $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $text);
        
        # Images
        $text = preg_replace('/!\[(.+?)\]\((.+?)\)/', '<img src="$2" alt="$1" />', $text);
        
        return $text;
    }

    protected $DefinitionData;
}

/*
 * NOTES IMPORTANTES POUR LA PRODUCTION :
 * 
 * Cette version est SIMPLIFIÉE pour la démonstration.
 * Elle gère les fonctionnalités de base mais peut ne pas couvrir tous les cas edge.
 * 
 * Pour une utilisation en production, téléchargez la version officielle complète :
 * 
 * Méthode 1 (Composer) :
 * composer require erusev/parsedown
 * 
 * Méthode 2 (Manuel) :
 * wget https://raw.githubusercontent.com/erusev/parsedown/master/Parsedown.php -O vendor/parsedown/Parsedown.php
 * 
 * Ou téléchargez depuis : https://github.com/erusev/parsedown/blob/master/Parsedown.php
 */