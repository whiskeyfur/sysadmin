<?php

namespace App\DTOs;

/**
 * One directive or section (<VirtualHost>, <Directory>, ...) of an Apache
 * configuration, where it is (file and lines, for editing in place), and
 * whether Apache would use it (inside a false <IfModule>/<IfDefine> it
 * wouldn't). See ApacheConfigTree.
 */
class ApacheNode
{
    /**
     * Sections that only say whether their contents count.
     */
    public const CONDITIONALS = ['ifmodule', 'ifdefine', 'ifversion', 'iffile', 'ifdirective', 'ifsection'];

    /**
     * @var list<ApacheNode> a section's contents, in order (included files in place)
     */
    public array $children = [];

    /**
     * @param 'block'|'directive' $kind
     * @param string $name lowercased
     * @param list<string> $args with ${VARIABLES} filled in
     * @param int $line first line (a section's opening line), 1-based
     * @param int $endLine last line (continued lines; a section's closing line)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly array $args,
        public readonly string $file,
        public readonly int $line,
        public int $endLine,
        public readonly bool $active = true,
        public readonly ?ApacheNode $parent = null,
    ) {
    }

    public function isBlock(string ...$names): bool
    {
        return $this->kind === 'block' && ($names === [] || in_array($this->name, $names, true));
    }

    public function arg(int $i = 0): string
    {
        return $this->args[$i] ?? '';
    }

    /**
     * Active children, looking through true conditional sections (<IfModule> and the like).
     *
     * @return list<ApacheNode>
     */
    public function effective(): array
    {
        $out = [];

        foreach ($this->children as $child) {
            if (!$child->active) {
                continue;
            }

            if ($child->isBlock(...self::CONDITIONALS)) {
                array_push($out, ...$child->effective());
            } else {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * Where it is, for people: "sites-enabled/002-sys.conf:12".
     */
    public function where(string $root = '/etc/apache2'): string
    {
        return (str_starts_with($this->file, "$root/") ? substr($this->file, strlen($root) + 1) : $this->file) . ':' . $this->line;
    }
}
