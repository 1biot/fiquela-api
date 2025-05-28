<?php

namespace Api\Endpoints;

use Api;
use Api\Utils\Stopwatch;
use FQL\Exception\FileNotFoundException;
use FQL\Exception\InvalidFormatException;
use Nette\Schema\ValidationException;
use Nette\Utils;
use Slim\Exception;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class Query extends Controller
{
    public const int DefaultLimit = 1000;

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        Stopwatch::start('query');
        try {
            $workspace = $this->getWorkspace($request);
            $data = $this->validateRequest($request, new Api\Schemas\Query);
            $file = $data['file'] ?? null;
            $refresh = $data['refresh'] ?? false;
            $query = $data['query'] ?? '';

            [$cachedQuery, $originQueryHash, $originalFileQuery] = $workspace->runQuery($query, $file, $refresh);
            $count = $cachedQuery->execute()->count();

            $paginator = new Utils\Paginator;
            $paginator->setItemCount($count);
            $paginator->setPage((int) ($data['page'] ?? 1));
            $paginator->setItemsPerPage(min(self::DefaultLimit, $count, (int) ($data['limit'] ?? self::DefaultLimit)));

            if ($paginator->getPageCount() > 1) {
                $cachedQuery->offset($paginator->getOffset())
                    ->limit($paginator->getItemsPerPage());
            }

            return $this->json(
                $response,
                [
                    'query' => (string) $query,
                    'file' => $file,
                    'hash' => $originQueryHash,
                    'data' => iterator_to_array($cachedQuery->execute()->getIterator()),
                    'elapsed' => round(Stopwatch::stop('query') * 1000, 2), // in milliseconds
                    'pagination' => [
                        'page' => $paginator->getPage(),
                        'pageCount' => $paginator->getPageCount(),
                        'itemCount' => $paginator->getItemCount(),
                        'itemsPerPage' => $paginator->getItemsPerPage(),
                        'offset' => $paginator->getOffset(),
                    ],
                ]
            );
        } catch (ValidationException $e) {
            throw new Api\Exceptions\UnprocessableContentHttpException($request, previous: $e);
        } catch (FileNotFoundException $e) {
            throw new Exception\HttpNotFoundException($request, previous: $e);
        } catch (InvalidFormatException $e) {
            throw new Exception\HttpBadRequestException($request, previous: $e);
        } catch (\Throwable $e) {
            throw new Exception\HttpInternalServerErrorException($request, previous: $e);
        }
    }
}
