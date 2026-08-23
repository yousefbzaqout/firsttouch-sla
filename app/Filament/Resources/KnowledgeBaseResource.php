<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\KnowledgePriority;
use App\Enums\KnowledgeType;
use App\Filament\Resources\KnowledgeBaseResource\Pages\CreateKnowledgeBase;
use App\Filament\Resources\KnowledgeBaseResource\Pages\EditKnowledgeBase;
use App\Filament\Resources\KnowledgeBaseResource\Pages\ListKnowledgeBases;
use App\Models\KnowledgeBase;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class KnowledgeBaseResource extends Resource
{
    protected static ?string $model = KnowledgeBase::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageTenantSettings();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')->required()->maxLength(255),
                Select::make('type')
                    ->options(KnowledgeType::class)
                    ->required()
                    ->live()
                    ->native(false),
                Select::make('priority')
                    ->options(KnowledgePriority::class)
                    ->default(KnowledgePriority::Normal->value)
                    ->dehydrated(false),
                Textarea::make('content')
                    ->label(fn (Get $get): string => self::contentLabel($get('type')))
                    ->rows(10)
                    ->visible(fn (Get $get): bool => self::typeRequiresText($get('type')))
                    ->required(fn (Get $get): bool => self::typeRequiresText($get('type')))
                    ->dehydrated(fn (Get $get): bool => self::typeRequiresText($get('type'))),
                FileUpload::make('document')
                    ->label('Document files')
                    ->helperText('Upload .txt, .md, .csv, .json, or .html files. Content is chunked into the knowledge base after save.')
                    ->disk('local')
                    ->directory('knowledge-documents')
                    ->visibility('private')
                    ->multiple()
                    ->acceptedFileTypes([
                        'text/plain',
                        'text/markdown',
                        'text/csv',
                        'application/json',
                        'text/html',
                        '.txt',
                        '.md',
                        '.markdown',
                        '.csv',
                        '.json',
                        '.html',
                        '.htm',
                    ])
                    ->maxSize(5120)
                    ->visible(fn (Get $get): bool => self::typeRequiresFile($get('type')))
                    ->required(fn (Get $get): bool => self::typeRequiresFile($get('type')))
                    ->dehydrated(fn (Get $get): bool => self::typeRequiresFile($get('type'))),
                Toggle::make('is_active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeBases::route('/'),
            'create' => CreateKnowledgeBase::route('/create'),
            'edit' => EditKnowledgeBase::route('/{record}/edit'),
        ];
    }

    private static function resolveType(mixed $type): ?KnowledgeType
    {
        if ($type instanceof KnowledgeType) {
            return $type;
        }

        if (is_string($type) && $type !== '') {
            return KnowledgeType::tryFrom($type);
        }

        return null;
    }

    private static function typeRequiresText(mixed $type): bool
    {
        return self::resolveType($type)?->requiresTextInput() ?? false;
    }

    private static function typeRequiresFile(mixed $type): bool
    {
        return self::resolveType($type)?->requiresFileUpload() ?? false;
    }

    private static function contentLabel(mixed $type): string
    {
        return self::resolveType($type)?->contentFieldLabel() ?? 'Content';
    }
}
